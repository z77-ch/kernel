<?php

namespace Z77\Persistence\Cleaning\Filters;

final class ListFilter implements FilterInterface
{
    public function __construct(private readonly FilterInterface $inner) {}

    public function apply(mixed $value): mixed
    {
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $cleaned = $this->inner->apply($item);
            if ($cleaned === null || $cleaned === '' || $cleaned === []) {
                continue;
            }
            $items[] = $cleaned;
        }
        return $items;
    }
}
