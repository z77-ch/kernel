<?php

namespace Z77\Persistence\File\Storage;

use Z77\Shared\Attributes\Entity as EntityAttr;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * Builds the per-record filename for document-mode entities (#[Entity(perRecord: true)]).
 *
 * The filename is the entity's key fields (#[Entity(keyBy: [...])]) joined by '.',
 * relative to the entity's directory: keyBy ['slug', 'language'] → '<dir>/<slug>.<language>.json'.
 * Empty optional keys are left out (see fromValues()).
 *
 * Single source of truth so FileEntityManager (write path) and DocumentRepository
 * (read path) can never diverge on how a record maps to a file.
 */
final class DocumentPath
{
    private function __construct() {}

    /** Build the path from an entity instance (uses getters on the key fields). */
    public static function forEntity(EntityAttr $attr, object $entity): string
    {
        $values = [];
        foreach ($attr->keyBy as $field) {
            $getter         = Naming::toGetter($field);
            $values[$field] = (string)$entity->$getter();
        }

        return self::fromValues($attr, $values);
    }

    /** Build the path from a findBy() criteria array (snake_case keys). */
    public static function forCriteria(EntityAttr $attr, array $criteria): string
    {
        $values = [];
        foreach ($attr->keyBy as $field) {
            $values[$field] = (string)($criteria[$field] ?? '');
        }

        return self::fromValues($attr, $values);
    }

    /**
     * An optional key (#[Entity(optionalKeys: [...])]) that is empty is left out of
     * the name, so adding an optional key to an entity does not rename the files
     * that already exist. Every other key part must be non-empty.
     */
    private static function fromValues(EntityAttr $attr, array $values): string
    {
        $parts = [];
        foreach ($values as $field => $value) {
            if (in_array($field, $attr->optionalKeys, true)
                && preg_replace('/[^a-z0-9_-]+/', '', strtolower($value)) === '') {
                continue;
            }
            $parts[] = self::part($value);
        }

        return self::build($attr->getPath(), $parts);
    }

    private static function part(string $value): string
    {
        $clean = preg_replace('/[^a-z0-9_-]+/', '', strtolower($value));
        if ($clean === '') {
            throw new \RuntimeException('Document key part is empty — cannot build a per-record filename.');
        }

        return $clean;
    }

    private static function build(string $dir, array $parts): string
    {
        return trim($dir, '/').'/'.implode('.', $parts).'.json';
    }
}
