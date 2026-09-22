<?php

namespace Z77\Shared\Services;

use Z77\Core\DI;
use Z77\Shared\Content\ContentPreview;
use Z77\Shared\Entities\Content;
use Z77\Shared\Repositories\ContentRepository;

/**
 * Variants, publishing and versions of content documents (ADR-044 addendum
 * "Variants", ADR-045 §3) — and the ONE place a live copy is written.
 *
 * A variant is a copy of a live document under a set key; all documents with
 * the same key form one SET (typically one writer delivery, every language).
 * {@see ContentPreview} shows a set on the website; publish() makes it live.
 *
 * The version rule (ADR-045): every write of a live copy — the backend editor
 * (save, add, active switch), publishing a set, restoring a version, the
 * frontend editor — goes through {@see writeLive()}:
 *   1. the stored live copy (if any) is archived as a one-document variant
 *      with the key `v-YYYYMMDD-HHMMSS` (`-2`, `-3` … if that document already
 *      has a variant with that key), keeping ITS changedBy/changedAt — a
 *      version says whose text it is;
 *   2. the new live copy is written, stamped with the saving user and time.
 * Saving a VARIANT writes no version and no stamp. Rollback = {@see restore()}
 * of a version, which itself archives the current live copy first. Versions
 * stay until an admin deletes them (the backend's delete is ADMIN-only).
 *
 * Publishing a set runs the rule once per document of the set, with one key for
 * the whole run (free for every document of it), so the versions of one publish
 * are recognisable as one run. The former `alt-YYYYMMDD-HHMM` archive SET is
 * gone — one rule for every live write. Existing `alt-…` sets are ordinary sets
 * and can still be published.
 *
 * Limits, stated rather than hidden:
 *   - NOT atomic across files. Each file write is atomic (FileStorage), a run is
 *     not: if it breaks in the middle, the documents handled so far are live and
 *     their variants gone, the rest is still a variant. Running publish() again
 *     finishes the set.
 *   - A document that had NO live copy before the publish gets no version, so
 *     nothing restores the state "absent"; delete it by hand.
 *
 * Backend (and frontend editor) only. Errors a user can cause (unknown set,
 * document already in the set) are thrown as \DomainException with a German
 * message meant for the flash.
 *
 * NOT a DI singleton — built on demand like {@see ContentService}.
 */
final class ContentVariantService
{
    /**
     * @param object $em the unified entity manager (persist / flush / remove) —
     *        typed loosely so the service can be exercised without DI
     */
    public function __construct(
        private ContentRepository $repository,
        private object $em
    ) {}

    public static function create(): self
    {
        $em = DI::getUnifiedEntityManager();

        return new self($em->getRepository(Content::class), $em);
    }

    /**
     * Copies a live document into the set $key and writes the copy. The copy
     * carries no changedBy/changedAt: variant saves are not stamped.
     *
     * @throws \DomainException if $live is not a live copy, $key is empty after
     *         normalization, or the set already contains this slug + language
     */
    public function createVariant(Content $live, string $key): Content
    {
        if (!$live->isLive()) {
            throw new \DomainException('Eine Variante wird nur von der Live-Fassung angelegt.');
        }
        $key = ContentPreview::normalize($key);
        if ($key === '') {
            throw new \DomainException('Der Name des Satzes ist leer.');
        }
        if ($this->repository->findBySlug($live->getSlug(), $live->getLanguage(), $key) !== null) {
            throw new \DomainException(
                'Der Satz «' . $key . '» enthält «' . $live->getSlug() . '» (' . $live->getLanguage() . ') bereits.'
            );
        }

        $variant = $this->copy($live, $key, false);
        $this->em->persist($variant);
        $this->em->flush();

        return $variant;
    }

    /**
     * Writes $new as the live copy of its slug + language (the version rule,
     * see the class comment). $new may be the loaded document the caller
     * changed, or a fresh object — the stored state is re-read from the store.
     *
     * @return string the key of the version the previous live copy became,
     *         '' when there was no previous live copy
     * @throws \LogicException if $new is not a live copy (a programming error)
     */
    public function saveLive(Content $new, string $username, ?\DateTimeImmutable $now = null): string
    {
        if (!$new->isLive()) {
            throw new \LogicException('saveLive() writes only the live copy; save a variant with persist().');
        }
        $now ??= new \DateTimeImmutable();
        $key   = $this->versionKey([$new], $now);

        return $this->writeLive($new, $username, $now, $key) ? $key : '';
    }

    /**
     * Makes the version $version the live copy again and removes it — the
     * current live copy becomes a new version first, so a restore is undone
     * by restoring that one. One document only; the other versions of the same
     * publish run stay as they are.
     *
     * @return string the key of the version the replaced live copy became ('' if none)
     * @throws \DomainException if $version is not a version
     */
    public function restore(Content $version, string $username, ?\DateTimeImmutable $now = null): string
    {
        if (!$version->isVersion()) {
            throw new \DomainException('Nur eine Version kann wiederhergestellt werden.');
        }
        $now ??= new \DateTimeImmutable();
        $key   = $this->versionKey([$version], $now);

        $archived = $this->writeLive($this->copy($version, '', false), $username, $now, $key);
        // Live (+ its version) are written before the restored version goes:
        // a break in between leaves the version in place, never a lost text.
        $this->em->remove($version);

        return $archived ? $key : '';
    }

    /**
     * Publishes every document of the set $key (all languages); see the class
     * comment for the steps and their limits.
     *
     * @return array{archive: string, published: list<array{slug: string, language: string, archived: bool}>}
     *         archive = the version key the previous live copies got, '' when
     *         none had to be archived
     * @throws \DomainException if the set is empty or unknown
     */
    public function publish(string $key, string $username, ?\DateTimeImmutable $now = null): array
    {
        $key       = ContentPreview::normalize($key);
        $documents = $this->repository->findByVariant($key);
        if ($documents === []) {
            throw new \DomainException('Der Satz «' . $key . '» existiert nicht oder ist leer.');
        }
        usort($documents, fn(Content $a, Content $b) =>
            [$a->getSlug(), $a->getLanguage()] <=> [$b->getSlug(), $b->getLanguage()]);

        $now        ??= new \DateTimeImmutable();
        $versionKey   = $this->versionKey($documents, $now);
        $anyArchived  = false;
        $published    = [];

        foreach ($documents as $variant) {
            $archived    = $this->writeLive($this->copy($variant, '', false), $username, $now, $versionKey);
            $anyArchived = $anyArchived || $archived;

            // Live + version are written before the variant goes: a break in
            // between leaves the variant in place, never a lost text.
            $this->em->remove($variant);

            $published[] = [
                'slug'     => $variant->getSlug(),
                'language' => $variant->getLanguage(),
                'archived' => $archived,
            ];
        }

        return ['archive' => $anyArchived ? $versionKey : '', 'published' => $published];
    }

    /**
     * THE live write: archive the stored live copy under $versionKey, stamp and
     * write $new. Every public live write above ends here.
     *
     * @return bool whether a previous live copy was archived
     */
    private function writeLive(Content $new, string $username, \DateTimeImmutable $now, string $versionKey): bool
    {
        $stored = $this->repository->findBySlug($new->getSlug(), $new->getLanguage());
        if ($stored !== null) {
            // A separate object read from the store: $new may be the very
            // document the caller loaded and changed.
            $this->em->persist($this->copy($stored, $versionKey, true));
        }

        $new->setVariant('');
        $new->setChangedBy($username);
        $new->setChangedAt($now->format(\DATE_ATOM));
        $this->em->persist($new);
        $this->em->flush();

        return $stored !== null;
    }

    /**
     * `v-YYYYMMDD-HHMMSS`, with `-2`, `-3` … while any of $documents already
     * has a variant with that key (same second, or restoring that very version).
     *
     * @param Content[] $documents
     */
    private function versionKey(array $documents, \DateTimeImmutable $now): string
    {
        for ($n = 1; ; $n++) {
            $key   = ContentPreview::versionKey($now, $n);
            $taken = false;
            foreach ($documents as $doc) {
                if ($this->repository->findBySlug($doc->getSlug(), $doc->getLanguage(), $key) !== null) {
                    $taken = true;
                    break;
                }
            }
            if (!$taken) {
                return $key;
            }
        }
    }

    /** $keepStamps: true for a version (it keeps whose text it is), false otherwise. */
    private function copy(Content $source, string $variant, bool $keepStamps): Content
    {
        $copy = new Content();
        $copy->setSlug($source->getSlug());
        $copy->setLanguage($source->getLanguage());
        $copy->setVariant($variant);
        $copy->setTitle($source->getTitle());
        $copy->setActive($source->isActive());
        $copy->setBlocks($source->getBlocks());
        if ($keepStamps) {
            $copy->setChangedBy($source->getChangedBy());
            $copy->setChangedAt($source->getChangedAt());
        }

        return $copy;
    }
}
