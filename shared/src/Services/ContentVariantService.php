<?php

namespace Z77\Shared\Services;

use Z77\Core\DI;
use Z77\Shared\Content\ContentPreview;
use Z77\Shared\Entities\Content;
use Z77\Shared\Repositories\ContentRepository;

/**
 * Creating and publishing content variants (ADR-044 addendum "Variants").
 *
 * A variant is a copy of a live document under a set key; all documents with
 * the same key form one SET (typically one writer delivery, every language).
 * {@see ContentPreview} shows a set on the website; publish() makes it live.
 *
 * Publishing a set, per document of the set:
 *   1. the current live copy (if any) is stored as a variant of ONE archive set
 *      for the whole run, `alt-YYYYMMDD-HHMM` (`-2`, `-3` … if that key exists);
 *   2. the variant's title, active flag and blocks become the live copy;
 *   3. the variant document is deleted.
 * The archive is an ordinary set — rolling back is publishing the archive set.
 *
 * Limits, stated rather than hidden:
 *   - NOT atomic across files. Each file write is atomic (FileStorage), a run is
 *     not: if it breaks in the middle, the documents handled so far are live and
 *     their variants gone, the rest is still a variant. Running publish() again
 *     finishes the set (under a new archive key).
 *   - A document that had NO live copy before the publish is not in the archive,
 *     so publishing the archive back does not remove it; delete it by hand.
 *
 * Backend only. Errors a user can cause (unknown set, document already in the
 * set) are thrown as \DomainException with a German message meant for the flash.
 *
 * NOT a DI singleton — built on demand like {@see ContentService}.
 */
final class ContentVariantService
{
    public const ARCHIVE_PREFIX = 'alt-';

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
     * Copies a live document into the set $key and writes the copy.
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

        $variant = $this->copy($live, $key);
        $this->em->persist($variant);
        $this->em->flush();

        return $variant;
    }

    /**
     * Publishes every document of the set $key (all languages); see the class
     * comment for the steps and their limits.
     *
     * @return array{archive: string, published: list<array{slug: string, language: string, archived: bool}>}
     *         archive = the archive set's key, '' when no live copy had to be archived
     * @throws \DomainException if the set is empty or unknown
     */
    public function publish(string $key, ?\DateTimeImmutable $now = null): array
    {
        $key       = ContentPreview::normalize($key);
        $documents = $this->repository->findByVariant($key);
        if ($documents === []) {
            throw new \DomainException('Der Satz «' . $key . '» existiert nicht oder ist leer.');
        }
        usort($documents, fn(Content $a, Content $b) =>
            [$a->getSlug(), $a->getLanguage()] <=> [$b->getSlug(), $b->getLanguage()]);

        $archiveKey = $this->archiveKey($now ?? new \DateTimeImmutable());
        $archived   = false;
        $published  = [];

        foreach ($documents as $variant) {
            $live    = $this->repository->findBySlug($variant->getSlug(), $variant->getLanguage());
            $hadLive = $live !== null;

            if ($hadLive) {
                // The archive copy is a separate object: persist() is deferred
                // until flush(), and $live is changed below.
                $this->em->persist($this->copy($live, $archiveKey));
                $archived = true;
            } else {
                $live = $this->copy($variant, '');
            }
            $live->setTitle($variant->getTitle());
            $live->setActive($variant->isActive());
            $live->setBlocks($variant->getBlocks());
            $this->em->persist($live);

            // Live + archive are written before the variant goes: a break in
            // between leaves the variant in place, never a lost text.
            $this->em->flush();
            $this->em->remove($variant);

            $published[] = [
                'slug'     => $variant->getSlug(),
                'language' => $variant->getLanguage(),
                'archived' => $hadLive,
            ];
        }

        return ['archive' => $archived ? $archiveKey : '', 'published' => $published];
    }

    /** `alt-YYYYMMDD-HHMM`, with `-2`, `-3` … appended while that set exists. */
    private function archiveKey(\DateTimeImmutable $now): string
    {
        $base     = self::ARCHIVE_PREFIX . $now->format('Ymd-Hi');
        $existing = $this->repository->variantKeys();

        $key = $base;
        for ($n = 2; in_array($key, $existing, true); $n++) {
            $key = $base . '-' . $n;
        }

        return $key;
    }

    private function copy(Content $source, string $variant): Content
    {
        $copy = new Content();
        $copy->setSlug($source->getSlug());
        $copy->setLanguage($source->getLanguage());
        $copy->setVariant($variant);
        $copy->setTitle($source->getTitle());
        $copy->setActive($source->isActive());
        $copy->setBlocks($source->getBlocks());

        return $copy;
    }
}
