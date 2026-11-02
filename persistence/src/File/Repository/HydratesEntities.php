<?php

namespace Z77\Persistence\File\Repository;

/**
 * Shared hydration for file-backed repositories. The mapping is {@see LoadedRows::hydrate()}; the
 * row read is remembered, so the store can tell later what a save actually changed.
 */
trait HydratesEntities
{
    protected function hydrate(string $class, array $row): object
    {
        $obj = LoadedRows::hydrate($class, $row);
        LoadedRows::remember($obj, $row);

        return $obj;
    }
}
