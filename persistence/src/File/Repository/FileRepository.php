<?php

namespace Z77\Persistence\File\Repository;

use Z77\Persistence\Interface\RepositoryInterface,
    Z77\Persistence\File\Storage\RecordStore,
    Z77\Shared\Libraries\Convention\Naming
;

/**
 * Read-only repository for file-backed entities — mode-agnostic.
 *
 * It owns the query semantics (find* + criteria matching) and hydration; *where*
 * the rows live (a single collection file vs. one file per record) is the
 * RecordStore's concern. When a findBy() criteria covers the store's key fields,
 * the row is fetched directly via byKey() (O(1)); otherwise all rows are scanned.
 */
class FileRepository implements RepositoryInterface
{
    use HydratesEntities;

    public function __construct(
        private string $class,
        private RecordStore $store
    ) {}

    public function findAll(): array
    {
        return array_map(fn($row) => $this->hydrate($this->class, $row), $this->store->all());
    }

    public function findBy(array $criteria): array
    {
        $keys = $this->store->keyFields();

        // Full key present → direct fetch, no scan.
        if ($keys !== [] && array_diff($keys, array_keys($criteria)) === []) {
            $row = $this->store->byKey($criteria);
            if ($row === null) {
                return [];
            }
            $entity = $this->hydrate($this->class, $row);

            return $this->matches($entity, $criteria) ? [$entity] : [];
        }

        // Partial / non-key criteria → scan + filter.
        return array_values(array_filter(
            $this->findAll(),
            fn($entity) => $this->matches($entity, $criteria)
        ));
    }

    public function findOneBy(array $criteria): ?object
    {
        return $this->findBy($criteria)[0] ?? null;
    }

    public function find(int|string $id): ?object
    {
        $keys = $this->store->keyFields();

        return match (count($keys)) {
            0       => $this->findOneBy(['id' => $id]),        // collection: int id
            1       => $this->findOneBy([$keys[0] => $id]),    // single-key document
            default => null,                                   // multi-key → use a domain method
        };
    }

    private function matches(object $entity, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            $getter = Naming::toGetter($key);
            if ($entity->$getter() !== $value) {
                return false;
            }
        }

        return true;
    }
}
