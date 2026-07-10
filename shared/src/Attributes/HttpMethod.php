<?php

namespace Z77\Shared\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class HttpMethod
{
    public readonly array $methods;

    public function __construct(string ...$methods)
    {
        if ($methods === []) {
            throw new \InvalidArgumentException('#[HttpMethod] requires at least one method name');
        }
        $this->methods = array_map('strtoupper', $methods);
    }
}
