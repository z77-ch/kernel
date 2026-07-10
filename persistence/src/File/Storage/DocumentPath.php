<?php

namespace Z77\Persistence\File\Storage;

use Z77\Shared\Attributes\Entity as EntityAttr;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * Builds the per-record filename for document-mode entities (#[Entity(perRecord: true)]).
 *
 * The filename is the entity's key fields (#[Entity(keyBy: [...])]) joined by '.',
 * relative to the entity's directory: keyBy ['slug', 'language'] → '<dir>/<slug>.<language>.json'.
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
        $parts = [];
        foreach ($attr->keyBy as $field) {
            $getter  = Naming::toGetter($field);
            $parts[] = self::part((string)$entity->$getter());
        }

        return self::build($attr->getPath(), $parts);
    }

    /** Build the path from a findBy() criteria array (snake_case keys). */
    public static function forCriteria(EntityAttr $attr, array $criteria): string
    {
        $parts = [];
        foreach ($attr->keyBy as $field) {
            $parts[] = self::part((string)($criteria[$field] ?? ''));
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
