<?php

namespace Z77\Persistence\File\Storage;

use Z77\Shared\Attributes\Entity as EntityAttr;

/**
 * Document mode: one file per record in a directory, named from the entity's
 * key fields (#[Entity(keyBy: [...])]) via DocumentPath.
 *
 * byKey() resolves the full key to a single file (O(1) — no scan). all() globs
 * the directory. Fits few but heavy records read one at a time (page content).
 */
final class DocumentStore implements RecordStore
{
    public function __construct(
        private FileStorage $storage,
        private EntityAttr $attr
    ) {}

    public function all(): array
    {
        $out = [];
        foreach ($this->storage->list($this->attr->getPath()) as $path) {
            $row = $this->storage->load($path);
            if ($row !== []) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function byKey(array $criteria): ?array
    {
        try {
            $path = DocumentPath::forCriteria($this->attr, $criteria);
        } catch (\RuntimeException) {
            return null;
        }

        $row = $this->storage->load($path);

        return $row === [] ? null : $row;
    }

    public function keyFields(): array
    {
        return $this->attr->keyBy;
    }

    public function persistAll(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->storage->save(
                DocumentPath::forEntity($this->attr, $entity),
                $entity->mapToArray()
            );
        }
    }

    public function delete(object $entity): void
    {
        $this->storage->delete(DocumentPath::forEntity($this->attr, $entity));
    }
}
