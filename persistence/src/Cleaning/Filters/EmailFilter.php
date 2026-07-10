<?php

namespace Z77\Persistence\Cleaning\Filters;

final class EmailFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $v = strtolower((string)$value);
        return preg_replace('/\s+/u', '', $v);
    }
}
