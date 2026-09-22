<?php

namespace Z77\Persistence\Resolver;

use Z77\Shared\Libraries\Convention\Naming;

/**
 * Convention discovery of an entity-specific repository, shared by every
 * driver's EntityManager: `{Root}\Entities\{Name}` → `{Root}\Repositories\{Name}Repository`.
 *
 * The driver decides what to construct it with (a RecordStore, a Doctrine
 * EntityManager); the convention itself lives once (Rule 8). A repository
 * found this way MUST extend the driver's base repository — see
 * persistence-architecture.md.
 */
final class RepositoryConvention
{
    /** The specific repository class for the entity, or null when none exists. */
    public static function specificRepository(string $entityClass): ?string
    {
        $pos = strrpos($entityClass, '\\Entities\\');
        if ($pos === false) {
            return null;
        }

        $repoClass = substr($entityClass, 0, $pos)
            . '\\Repositories\\' . Naming::toClassBaseName($entityClass) . 'Repository';

        return class_exists($repoClass) ? $repoClass : null;
    }
}
