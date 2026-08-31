<?php

namespace Z77\Shared\Attributes;

/**
 * Marks an entity property as a foreign key for the data import (ADR-032).
 * The value is a record id of $targetClass. Import never copies it verbatim:
 *
 *  - identity/near-match rules naming this property compare the RESOLVED
 *    identity of the referenced record, never the raw number;
 *  - the content comparison treats two refs as equal when the target-side
 *    value points at the matched counterpart of the source-side value;
 *  - apply rewrites the value through the per-run source-id → target-id map.
 *
 * $resolveBy declares HOW a source value finds its target (IMP-R017):
 *  - 'map'      (default): per-run id map — the referenced record travels in
 *                the same import run.
 *  - 'identity': the source value addresses the target by the target class's
 *                natural identity (multi-run migrations: an order referencing
 *                an already-imported customer). Declared for v2 — the v1
 *                planner rejects it explicitly instead of guessing.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ImportRef
{
    public function __construct(
        public readonly string $targetClass,
        public readonly string $resolveBy = 'map'
    ) {}
}
