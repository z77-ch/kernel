<?php

namespace Z77\Persistence\Cleaning\Filters;

final class IntFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        return (int)$value;
    }
}
