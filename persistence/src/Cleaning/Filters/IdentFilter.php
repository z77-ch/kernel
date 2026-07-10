<?php

namespace Z77\Persistence\Cleaning\Filters;

final class IdentFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $v = trim((string)$value);
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $v);
    }
}
