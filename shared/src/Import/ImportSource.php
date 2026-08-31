<?php

namespace Z77\Shared\Import;

/**
 * The reader seam (ADR-032 §11, IMP-R015): a source yields raw record arrays
 * per target entity class — the planner, matcher and applier never touch a
 * raw format. v1 readers parse native entity JSON; v2 adds the mapping-driven
 * readers for foreign schemas (mysqldump). Anything a reader cannot turn into
 * records is its own error ({@see ImportSourceException}), raised on read —
 * a source that loads is structurally a record set.
 */
interface ImportSource
{
    /**
     * @return array<class-string, list<array<string, mixed>>> raw records per
     *         target entity class, in source order. Related record sets travel
     *         together — refs inside a set resolve against the ids of the sets
     *         in this same source, never against the target installation.
     */
    public function recordSets(): array;

    /** Human-readable origin, shown in the plan («vendor defaults», a file name). */
    public function label(): string;
}
