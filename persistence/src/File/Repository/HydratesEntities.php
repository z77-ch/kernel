<?php

namespace Z77\Persistence\File\Repository;

use Z77\Shared\Libraries\Convention\Naming;

/**
 * Shared hydration for file-backed repositories.
 *
 * Maps a raw JSON row onto an entity: public setters via mapFromArray(), and
 * server-controlled properties without a setter (id, tree refs, …) via reflection.
 * The reflection target set is computed once per class and cached.
 */
trait HydratesEntities
{
    /** @var array<class-string, array<string, \ReflectionProperty>> */
    private static array $reflectionTargets = [];

    protected function hydrate(string $class, array $row): object
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
