<?php

namespace Z77\Persistence\Cleaning\Filters;

final class TextFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $v = (string)$value;
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = preg_replace('/\s+/u', ' ', $v);
        return trim($v);
    }
}
