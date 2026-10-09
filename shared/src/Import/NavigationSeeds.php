<?php

namespace Z77\Shared\Import;

use Z77\Shared\Entities\Navigation;

/**
 * Per-package navigation seeds (ADR-050): every package ships its menu entries in
 * `data/framework/routing/navigation.d/<package>.json` — a JSON list of entries shaped
 * like `navigation.json` records, EXCEPT that they carry no `id` / `parent_id` / `ref`:
 * an entry names its parent by `parent_key` (the `key` of an entry in ANY package's
 * seed, null for a root area, which carries `slot`) and a ref entry names its target by
 * `ref_key`. Order among siblings is the entry's `sort_key`.
 *
 * Two consumers, one conversion:
 *  - the installer ({@see merge()}): ADD-only — every seed entry the installation does
 *    not already hold (recognised by the import's identity rules, {@see ImportPlanner})
 *    is appended at the end of its siblings; nothing existing is ever changed;
 *  - the backend import ({@see toRecords()}): the union of all seeds becomes the
 *    Navigation record set of the vendor source, with in-source ids, so the planner
 *    resolves `parent_key` against the TARGET through its identity matches.
 *
 * DI-free on purpose: the installer runs inside Composer, before any container exists.
 */
final class NavigationSeeds
{
    /** Relative to a package's data root. */
    public const SEED_DIR = 'framework/routing/navigation.d';

    /** The record shape (and field order) navigation.json is written in. */
    private const FIELDS = [
        'id' => null, 'key' => null, 'name' => '', 'module' => '', 'group' => '', 'controller' => '',
        'action' => '', 'slot' => '', 'ref' => null, 'active' => true, 'param' => '',
        'sort_key' => 0, 'parent_id' => null,
    ];

    /**
     * Seed files under the given data roots — ONE per file name, the first root wins
     * (roots in precedence order: a project override tier before the vendor packages,
     * CE principle). Returned sorted by file name, so the installer and the import see
     * the same order whatever order the roots came in.
     *
     * @param string[] $dataRoots absolute package data roots (`…/data`)
     * @return list<string> absolute file paths
     */
    public static function discover(array $dataRoots): array
    {
        $byName = [];
        foreach ($dataRoots as $root) {
            $dir = rtrim(str_replace('\\', '/', $root), '/') . '/' . self::SEED_DIR;
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                $byName[basename($file)] ??= str_replace('\\', '/', $file);
            }
        }
        ksort($byName, SORT_STRING);
        return array_values($byName);
    }

    /**
     * Reads the seed files. Each entry gets an `_origin` (the file name) for messages.
     * A malformed file is a packaging defect and throws — never a silently shorter menu.
     *
     * @param list<string> $files
     * @return list<array<string, mixed>>
     * @throws ImportSourceException
     */
    public static function read(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            $raw = is_readable($file) ? file_get_contents($file) : false;
            if ($raw === false) {
                throw new ImportSourceException("Navigation seed not readable: {$file}");
            }
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ImportSourceException("Malformed JSON in navigation seed {$file}: {$e->getMessage()}", 0, $e);
            }
            if (!is_array($decoded) || ($decoded !== [] && !array_is_list($decoded))) {
                throw new ImportSourceException("Navigation seed must be a JSON list of entries: {$file}");
            }

            foreach ($decoded as $i => $entry) {
                $where = basename($file) . " entry #{$i}";
                if (!is_array($entry) || !is_string($entry['name'] ?? null) || $entry['name'] === '') {
                    throw new ImportSourceException("Navigation seed {$where}: an entry needs a name.");
                }
                if (array_key_exists('id', $entry) || array_key_exists('parent_id', $entry) || array_key_exists('ref', $entry)) {
                    throw new ImportSourceException(
                        "Navigation seed {$where}: ids are local to one installation — use parent_key / ref_key, not id / parent_id / ref."
                    );
                }
                $isRoot = ($entry['parent_key'] ?? null) === null;
                $slot   = (string) ($entry['slot'] ?? '');
                if ($isRoot === ($slot === '')) {
                    throw new ImportSourceException(
                        "Navigation seed {$where}: a root area carries a slot and no parent_key; a child carries a parent_key and no slot."
                    );
                }
                $entry['_origin'] = basename($file);
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Converts seed entries into native Navigation records with in-source ids
     * (continuing after the highest id of $base), `parent_key` / `ref_key` resolved to
     * those ids. A key is looked up among the seeds AND the $base records (the import's
     * vendor source also carries the kernel's `navigation.default.json`).
     *
     * An entry whose parent_key / ref_key names no known entry — its package is not
     * installed — is an orphan, and so is every entry below it: orphans are NOT part of
     * the records (the installer reports them; they wait until the package arrives).
     *
     * @param list<array<string, mixed>> $seeds from {@see read()}
     * @param list<array<string, mixed>> $base  native records already in the source
     * @return array{records: list<array<string, mixed>>, origins: array<int, string>, orphans: list<array{entry: array, reason: string}>}
     * @throws ImportSourceException on a key declared twice
     */
    public static function toRecords(array $seeds, array $base = []): array
    {
        $nextId = 1;
        $keyIds = [];
        foreach ($base as $record) {
            $id = $record['id'] ?? null;
            if (is_int($id)) {
                $nextId = max($nextId, $id + 1);
                if (($record['key'] ?? null) !== null && $record['key'] !== '') {
                    $keyIds[$record['key']] = $id;
                }
            }
        }

        $ids = [];
        foreach ($seeds as $i => $seed) {
            $ids[$i] = $nextId++;
            $key = $seed['key'] ?? null;
            if ($key === null || $key === '') {
                continue;
            }
            if (isset($keyIds[$key])) {
                throw new ImportSourceException("Navigation key «{$key}» is declared twice (again in {$seed['_origin']}).");
            }
            $keyIds[$key] = $ids[$i];
        }

        // Resolve, then drop orphans transitively (a child of a dropped entry has no parent either).
        $orphans = [];
        $dropped = [];   // seed index => reason
        do {
            $changed = false;
            $droppedIds = [];
            foreach (array_keys($dropped) as $d) {
                $droppedIds[$ids[$d]] = $seeds[$d];
            }
            foreach ($seeds as $i => $seed) {
                if (isset($dropped[$i])) {
                    continue;
                }
                foreach (['parent_key' => 'parent', 'ref_key' => 'ref'] as $field => $label) {
                    $ref = $seed[$field] ?? null;
                    if ($ref === null || $ref === '') {
                        continue;
                    }
                    if (!isset($keyIds[$ref])) {
                        $dropped[$i] = "its {$label} «{$ref}» is not installed";
                    } elseif (isset($droppedIds[$keyIds[$ref]])) {
                        $dropped[$i] = "its {$label} «{$ref}» was skipped";
                    }
                    if (isset($dropped[$i])) {
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        $records = [];
        $origins = [];
        foreach ($seeds as $i => $seed) {
            if (isset($dropped[$i])) {
                $orphans[] = ['entry' => $seed, 'reason' => $dropped[$i]];
                continue;
            }
            $parentKey = $seed['parent_key'] ?? null;
            $refKey    = $seed['ref_key'] ?? null;

            $record = self::shape($seed);
            $record['id']        = $ids[$i];
            $record['parent_id'] = ($parentKey === null || $parentKey === '') ? null : $keyIds[$parentKey];
            $record['ref']       = ($refKey === null || $refKey === '') ? null : $keyIds[$refKey];

            $records[]           = $record;
            $origins[$ids[$i]]   = (string) ($seed['_origin'] ?? '');
        }

        return ['records' => $records, 'origins' => $origins, 'orphans' => $orphans];
    }

    /**
     * The installer's step (ADR-050 §3): ADDS every seed entry the installation does not
     * already hold, and changes nothing else. Recognition is the import's identity
     * (key → route → parent + ref, bijective — {@see ImportPlanner}); an entry the planner
     * cannot place with certainty (unclear, blocked, invalid) is skipped and named, as is
     * an entry whose parent package is missing. An added entry gets the next free id, its
     * parent resolved by parent_key (an existing entry or one added in this same run), and
     * a sort_key after its existing siblings — new siblings among themselves keep the seed
     * order.
     *
     * @param list<array<string, mixed>> $seeds  from {@see read()}
     * @param list<array<string, mixed>> $target the installation's navigation.json records
     * @return array{records: list<array<string, mixed>>, added: list<array<string, mixed>>, skipped: list<string>}
     *         records = $target unchanged + the added entries appended
     */
    public static function merge(array $seeds, array $target): array
    {
        $converted = self::toRecords($seeds);
        $skipped   = [];
        foreach ($converted['orphans'] as $orphan) {
            $skipped[] = self::label($orphan['entry']['name'], $orphan['entry']['_origin'] ?? '') . ': ' . $orphan['reason'];
        }

        $plan = (new ImportPlanner())->plan(
            [Navigation::class => $converted['records']],
            [Navigation::class => $target]
        );

        $bySource = [];   // source id => target id (existing or newly assigned)
        $new      = [];   // plan entries to add, in source order
        foreach ($plan->entries as $entry) {
            $srcId = $entry->record['id'];
            switch ($entry->outcome) {
                case ImportOutcome::Skipped:
                case ImportOutcome::Changed:
                    // Recognised: the installation holds it — as it is, whatever it made of it.
                    $bySource[$srcId] = $entry->targetId;
                    break;
                case ImportOutcome::NewRecord:
                    $new[] = $entry;
                    break;
                default:
                    $skipped[] = self::label((string) $entry->record['name'], $converted['origins'][$srcId] ?? '')
                        . ': ' . $entry->reason;
            }
        }

        $nextId  = 1;
        $maxSort = [];   // sibling group => highest sort_key
        foreach ($target as $record) {
            $nextId = max($nextId, (int) ($record['id'] ?? 0) + 1);
            $group  = self::siblingGroup($record['parent_id'] ?? null, (string) ($record['slot'] ?? ''));
            $maxSort[$group] = max($maxSort[$group] ?? -1, (int) ($record['sort_key'] ?? 0));
        }

        foreach ($new as $entry) {
            $bySource[$entry->record['id']] = $nextId++;
        }

        // Seed order within a sibling group: seed sort_key, then source order (stable sort).
        $ordered = $new;
        usort($ordered, static fn(ImportPlanEntry $a, ImportPlanEntry $b): int =>
            [$a->record['sort_key'], $a->sourceIndex] <=> [$b->record['sort_key'], $b->sourceIndex]);

        $added = [];
        foreach ($ordered as $entry) {
            $src    = $entry->record;
            $record = self::shape($src);
            $record['id']        = $bySource[$src['id']];
            $record['parent_id'] = $src['parent_id'] === null ? null : $bySource[$src['parent_id']];
            $record['ref']       = $src['ref'] === null ? null : $bySource[$src['ref']];

            $group = self::siblingGroup($record['parent_id'], $record['slot']);
            $record['sort_key'] = $maxSort[$group] = ($maxSort[$group] ?? -1) + 1;

            $added[] = $record;
        }
        // Written in id order, the way navigation.json grows.
        usort($added, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

        return ['records' => array_merge($target, $added), 'added' => $added, 'skipped' => $skipped];
    }

    /** One entry in the navigation.json field order, defaults for what the seed leaves out. */
    private static function shape(array $entry): array
    {
        $record = [];
        foreach (self::FIELDS as $field => $default) {
            $record[$field] = $entry[$field] ?? $default;
        }
        if ($record['key'] === '') {
            $record['key'] = null;
        }
        return $record;
    }

    /** Siblings = children of one parent, or the roots of one slot (TreeService scope). */
    private static function siblingGroup(?int $parentId, string $slot): string
    {
        return $parentId !== null ? 'p:' . $parentId : 's:' . $slot;
    }

    private static function label(string $name, string $origin): string
    {
        return "«{$name}»" . ($origin !== '' ? " ({$origin})" : '');
    }
}
