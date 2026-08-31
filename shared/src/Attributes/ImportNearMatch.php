<?php

namespace Z77\Shared\Attributes;

/**
 * Near-match heuristic for a data import (ADR-032, IMP-R003): a source record
 * that no identity rule could match is compared against the still-unmatched
 * target records on these property names. Exactly one hit downgrades the
 * record from `new` to `unclear` with that target as the suggested match —
 * the pass that catches "same record, but the identity fields were changed"
 * (a framework rename, a hand-created container without a key).
 *
 * Deliberately narrow (decision 2026-08-08): equality only, no fuzziness.
 * Properties carrying {@see ImportRef} compare by resolved counterpart, like
 * the content hash does. Omit the attribute to disable the pass (e.g.
 * NavigationAlias: a differing path IS a different alias, never a rename).
 *
 *   #[ImportNearMatch(['parentId', 'name', 'slot'])]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ImportNearMatch
{
    /** @param list<string> $fields */
    public function __construct(public readonly array $fields)
    {
    }
}
