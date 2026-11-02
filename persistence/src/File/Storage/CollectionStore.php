<?php

namespace Z77\Persistence\File\Storage;

use Z77\Persistence\File\Repository\LoadedRows;
use Z77\Shared\Attributes\Entity as EntityAttr;

/**
 * Collection mode: one JSON file holds an array of all records of an entity type.
 *
 * Identity is an auto-increment int `id` (max+1, never a deleted one's). Writes are batched — the whole
 * file is loaded once, all pending entities upserted by id, then saved once.
 * Fits small, homogeneous record sets read together (navigation, users).
 */
final class CollectionStore implements RecordStore
{
    private const HIGHEST_ID_SUFFIX = '.highest-id';

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
        // The whole read-merge-write cycle (incl. nextId) runs under the file's
        // exclusive lock — a concurrent writer would otherwise merge into the
        // same base state and drop this write (or hand out a duplicate id).
        $path = $this->attr->getPath();
        $this->storage->withExclusiveLock($path, function () use ($path, $entities): void {
            $data = $this->storage->load($path);

            foreach ($entities as $entity) {
                $isNew = !$entity->getId();
                if ($isNew) {
                    $ref = new \ReflectionProperty($entity::class, 'id');
                    $ref->setValue($entity, $this->nextId($data));
                }

                $row   = $entity->mapToArray();
                $found = false;
                foreach ($data as &$existing) {
                    if ($existing['id'] === $entity->getId()) {
                        $row      = $this->merged($entity, $row, $existing);
                        $existing = $row;
                        $found    = true;
                        break;
                    }
                }
                unset($existing);
                if (!$found) {
                    // It was read and its row is gone: deleted in the meantime. A save must not
                    // bring a deleted record back (DATA-MERGE-001).
                    if (!$isNew && LoadedRows::isLoaded($entity)) {
                        error_log('CollectionStore: save of ' . $entity::class . ' id ' . $entity->getId() . ' skipped, the row was deleted since it was read');
                        continue;
                    }
                    $data[] = $row;
                }
                LoadedRows::remember($entity, $row);
            }

            $this->storage->save($path, $data);
        });
    }

    /**
     * What a save writes over an existing row: only the columns THIS request changed; every other
     * column keeps what the row holds now (persistence-file.md DATA-MERGE-001). A stale read
     * therefore cannot undo what another request wrote since. A #[Counter] column moves by its
     * difference. A key that stood in the row when it was read and is gone now was removed by hand:
     * it stays gone.
     *
     * ⚠️ «What the row holds now» means read as an entity, through the setters: the same shape a
     * whole-row write would produce, so a tolerated value (a null a setter turns into []) and
     * normalising on save stay as they were, and what goes back into the entity fits its types.
     *
     * The result goes back into the entity, so it stays true for a second save of the same object.
     * An entity not read through a repository has nothing to compare with: its whole row, as given.
     *
     * @param array<string, mixed> $mine
     * @param array<string, mixed> $disk
     * @return array<string, mixed>
     */
    private function merged(object $entity, array $mine, array $disk): array
    {
        $read = LoadedRows::baseline($entity);
        if ($read === null) {
            return $mine;
        }

        try {
            $now = LoadedRows::hydrate($entity::class, $disk)->mapToArray();
        } catch (\TypeError $e) {
            error_log('CollectionStore: row of ' . $entity::class . ' id ' . $entity->getId() . ' no longer reads as an entity, written whole — ' . $e->getMessage());
            return $mine;
        }

        $readRow  = LoadedRows::readRow($entity) ?? [];
        $counters = LoadedRows::counters($entity::class);
        foreach ($mine as $key => $value) {
            if (!array_key_exists($key, $read) || !array_key_exists($key, $now)) {
                continue;
            }

            if ($read[$key] === $value) {
                if (!array_key_exists($key, $disk) && array_key_exists($key, $readRow)) {
                    unset($mine[$key]);
                    LoadedRows::writeBack($entity, $key, $now[$key]);
                    continue;
                }
                $next = $now[$key];
            } elseif (in_array($key, $counters, true)) {
                $next = (int) $now[$key] + ((int) $value - (int) $read[$key]);
            } else {
                continue;
            }

            if ($next !== $value) {
                $mine[$key] = $next;
                LoadedRows::writeBack($entity, $key, $next);
            }
        }

        return $mine;
    }

    public function delete(object $entity): void
    {
        $path = $this->attr->getPath();
        $this->storage->withExclusiveLock($path, function () use ($path, $entity): void {
            $data = $this->storage->load($path);
            $data = array_filter($data, fn($row) => $row['id'] !== $entity->getId());
            $this->storage->save($path, array_values($data));
            $this->rememberHighestId($path, $entity->getId());
        });
    }

    /**
     * Ids are never handed out twice: a session, a link or a stale save still holding a deleted id
     * must not fit the next record. Live rows carry the highest id themselves, so only a delete has
     * to leave a mark. Next to the file, not *.json: list() does not see it. A row removed by hand
     * leaves no mark.
     */
    private function rememberHighestId(string $path, mixed $id): void
    {
        if (is_int($id) && $id > $this->highestDeletedId($path)) {
            $this->storage->save($path . self::HIGHEST_ID_SUFFIX, ['highest_deleted_id' => $id]);
        }
    }

    private function highestDeletedId(string $path): int
    {
        return (int) ($this->storage->load($path . self::HIGHEST_ID_SUFFIX)['highest_deleted_id'] ?? 0);
    }

    private function nextId(array $data): int
    {
        $ids = array_column($data, 'id');
        return max($ids ? max($ids) : 0, $this->highestDeletedId($this->attr->getPath())) + 1;
    }
}
