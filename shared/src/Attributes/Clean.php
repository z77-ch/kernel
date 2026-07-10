<?php

namespace Z77\Shared\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Clean
{
    public readonly array $filters;

    public function __construct(string ...$filters)
    {
        if ($filters === []) {
            throw new \InvalidArgumentException('#[Clean] requires at least one filter name');
        }
        $this->filters = $filters;
    }
}
