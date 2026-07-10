<?php

namespace Z77\Persistence\Cleaning\Filters;

interface FilterInterface
{
    public function apply(mixed $value): mixed;
}
