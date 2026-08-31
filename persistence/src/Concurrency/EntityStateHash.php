<?php

namespace Z77\Persistence\Concurrency;

/**
 * Optimistic-locking hash over an entity's state.
 *
 * The edit form carries this hash of the STORED state as a hidden field
 * (entity_hash, next to entity_csrf); on save the controller re-reads the
 * entity and lets EntityValidator::guardStoredState() compare — a mismatch
 * means someone else saved in between, and the save is rejected through the
 * normal validation channel instead of silently overwriting.
 *
 * Hash source is mapToArray() (the ArrayMappable contract), normalised by a
 * recursive key sort so property order cannot matter. Carrier-neutral by
 * design: a driver with native versioning (e.g. a Doctrine version column)
 * replaces only this hash source — the form/validator flow stays identical.
 */
final class EntityStateHash
{
    public static function of(object $entity): string
    {
        $state = $entity->mapToArray();
        self::sortKeys($state);

        return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    private static function sortKeys(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                self::sortKeys($value);
            }
        }
        unset($value);
    }
}
