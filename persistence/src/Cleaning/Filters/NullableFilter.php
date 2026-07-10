<?php

namespace Z77\Persistence\Cleaning\Filters;

final class NullableFilter implements FilterInterface
{
    public function __construct(private readonly FilterInterface $inner) {}

    public function apply(mixed $value): mixed
    {
        $cleaned = $this->inner->apply($value);
        if ($cleaned === null || $cleaned === '' || $cleaned === []) {
            return null;
        }
        return $cleaned;
    }
}
