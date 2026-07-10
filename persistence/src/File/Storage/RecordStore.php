<?php

namespace Z77\Persistence\File\Storage;

/**
 * Record-level storage strategy for the file driver.
 *
 * Encapsulates *where and how* an entity's records live — a single collection
 * file (CollectionStore) or one file per record (DocumentStore). The repository
 * stays mode-agnostic: it asks the store for raw rows and does the query semantics
 * (criteria matching) + hydration itself.
 *
 * Rows are plain associative arrays (the JSON shape); hydration is the repository's job.
 */
interface RecordStore
{
    /** All raw rows in this store. */
    public function all(): array;

    /**
     * The raw row matching the full natural key, or null on miss.
     * Only meaningful when keyFields() is non-empty (fast direct fetch).
     */
    public function byKey(array $criteria): ?array;

    /**
     * Snake_case field names that allow a direct byKey() fetch.
     * Empty array → no fast key path (every lookup is a scan), e.g. collection mode.
     */
    public function keyFields(): array;

    /** Persist a batch of entities (collection: one file rewrite; document: one file each). */
    public function persistAll(array $entities): void;

    /** Delete a single entity's record. */
    public function delete(object $entity): void;
}
