<?php

namespace Z77\Persistence\File\Storage;

use Z77\Shared\Attributes\Entity as EntityAttr;

/**
 * Collection mode: one JSON file holds an array of all records of an entity type.
 *
 * Identity is an auto-increment int `id` (max+1). Writes are batched — the whole
 * file is loaded once, all pending entities upserted by id, then saved once.
 * Fits small, homogeneous record sets read together (navigation, users).
 */
final class CollectionStore implements RecordStore
{
    public function __construct(
        private FileStorage $storage,
        private EntityAttr $attr
    ) {}

    public function all(): array
    {
        return $this->storage->load($this->attr->getPath());
    }

    public function byKey(array $criteria): ?array
    {
        return null; // no fast key path — collection lookups always scan
    }

    public function keyFields(): array
    {
        return [];
    }

    public function persistAll(array $entities): void
    {
        $path = $this->attr->getPath();
        $data = $this->storage->load($path);

        foreach ($entities as $entity) {
            if (!$entity->getId()) {
                $ref = new \ReflectionProperty($entity::class, 'id');
                $ref->setValue($entity, $this->nextId($data));
            }

            $row   = $entity->mapToArray();
            $found = false;
            foreach ($data as &$existing) {
                if ($existing['id'] === $entity->getId()) {
                    $existing = $row;
                    $found    = true;
                    break;
                }
            }
            unset($existing);
            if (!$found) {
                $data[] = $row;
            }
        }

        $this->storage->save($path, $data);
    }

    public function delete(object $entity): void
    {
        $path = $this->attr->getPath();
        $data = $this->storage->load($path);
        $data = array_filter($data, fn($row) => $row['id'] !== $entity->getId());
        $this->storage->save($path, array_values($data));
    }

    private function nextId(array $data): int
    {
        $ids = array_column($data, 'id');
        return $ids ? max($ids) + 1 : 1;
    }
}
