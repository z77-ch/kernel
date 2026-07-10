<?php

namespace Z77\Persistence\Cleaning\Filters;

final class SlugFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $v = strtolower(trim((string)$value));
        $v = str_replace('\\', '/', $v);
        return preg_replace('/[^a-z0-9_\-\/]/', '', $v);
    }
}
