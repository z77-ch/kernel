<?php

namespace Z77\Persistence\Cleaning\Filters;

final class BoolFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if ($value === null || $value === false || $value === 0 || $value === '0' || $value === '' || $value === 'false') {
            return false;
        }
        return true;
    }
}
