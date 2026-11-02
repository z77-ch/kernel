<?php

namespace Z77\Persistence\File\Repository;

use Z77\Shared\Attributes\Counter;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * Remembers what each entity looked like when it was read, so a save can tell what THIS request
 * changed from what merely stood in the row (CollectionStore::persistAll, persistence-file.md
 * DATA-MERGE-001). Hydration itself lives here too: the baseline must come from exactly the same
 * mapping as the entity it is compared with, setters included.
 *
 * Held in a WeakMap, gone with the entity. The baseline is computed only on a save, so a list of
 * hundreds of rows costs one array reference each.
 */
final class LoadedRows
{
    private static ?\WeakMap $rows = null;

    /** @var array<class-string, array<string, \ReflectionProperty>> */
    private static array $reflectionTargets = [];

    /** @var array<class-string, list<string>> */
    private static array $counters = [];

    /**
     * Maps a raw JSON row onto a new entity: public setters via mapFromArray(), and
     * server-controlled properties without a setter (id, tree refs, …) via reflection.
     */
    public static function hydrate(string $class, array $row): object
    {
        $obj = new $class();
        $obj->mapFromArray($row);

        foreach (self::reflectionTargets($class) as $key => $prop) {
            if (array_key_exists($key, $row)) {
                $prop->setValue($obj, $row[$key]);
            }
        }

        return $obj;
    }

    public static function remember(object $entity, array $row): void
    {
        self::$rows ??= new \WeakMap();
        self::$rows[$entity] = $row;
    }

    public static function isLoaded(object $entity): bool
    {
        return self::$rows !== null && isset(self::$rows[$entity]);
    }

    /** The raw row as it was read; null for an entity not read through a repository. */
    public static function readRow(object $entity): ?array
    {
        return self::isLoaded($entity) ? self::$rows[$entity] : null;
    }

    /**
     * The entity as it was read, in mapToArray() shape; null for one that was not read through a
     * repository (a new entity, or one built by hand): nothing to compare with.
     */
    public static function baseline(object $entity): ?array
    {
        if (!self::isLoaded($entity)) {
            return null;
        }

        return self::hydrate($entity::class, self::$rows[$entity])->mapToArray();
    }

    /** @return list<string> the snake_case keys of the entity's #[Counter] properties */
    public static function counters(string $class): array
    {
        if (isset(self::$counters[$class])) {
            return self::$counters[$class];
        }

        $keys = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $prop) {
            if ($prop->getAttributes(Counter::class) !== []) {
                $keys[] = Naming::toSnakeCase($prop->getName());
            }
        }

        return self::$counters[$class] = $keys;
    }

    /** Sets a property by its row key, without a setter: the merge's result goes back into the entity. */
    public static function writeBack(object $entity, string $key, mixed $value): void
    {
        foreach ((new \ReflectionClass($entity))->getProperties() as $prop) {
            if (Naming::toSnakeCase($prop->getName()) === $key) {
                $prop->setValue($entity, $value);
                return;
            }
        }
    }

    /** @return array<string, \ReflectionProperty> */
    private static function reflectionTargets(string $class): array
    {
        if (isset(self::$reflectionTargets[$class])) {
            return self::$reflectionTargets[$class];
        }

        $ref     = new \ReflectionClass($class);
        $targets = [];
        foreach ($ref->getProperties() as $prop) {
            $setter = Naming::toSetter($prop->getName());
            if (!method_exists($class, $setter)) {
                $targets[Naming::toSnakeCase($prop->getName())] = $prop;
            }
        }

        return self::$reflectionTargets[$class] = $targets;
    }
}
