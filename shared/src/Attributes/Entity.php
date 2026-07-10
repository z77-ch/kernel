<?php

namespace Z77\Shared\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Entity
{
    public mixed $driver = null;

    /**
     * @param string $driverName       storage driver key (e.g. 'file')
     * @param string $path             single collection file (default mode) OR
     *                                 directory when $perRecord is true
     * @param bool   $invalidatesCache clear DataCache + PageCache after writes
     * @param bool   $perRecord        true → one file per record (document mode);
     *                                 false → one file holds the whole collection
     * @param array  $keyBy            snake_case property names whose values build
     *                                 the per-record filename (e.g. ['slug', 'language']
     *                                 → '<slug>.<language>.json'). Required when $perRecord
     */
    public function __construct(
        public readonly string $driverName,
        public readonly string $path = '',
        public readonly bool $invalidatesCache = false,
        public readonly bool $perRecord = false,
        public readonly array $keyBy = []
    ) {}

    public function getDriverName(): string
    {
        return $this->driverName;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
