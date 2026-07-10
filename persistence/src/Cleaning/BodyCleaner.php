<?php

namespace Z77\Persistence\Cleaning;

use Z77\Persistence\Cleaning\Filters\FilterInterface;
use Z77\Shared\Attributes\Clean;
use Z77\Shared\Libraries\Convention\Naming;

final class BodyCleaner
{
    /** @var array<class-string, array<string, FilterInterface|null>> */
    private static array $plans = [];

    private static ?FilterRegistry $registry = null;

    public static function cleanFor(string $entityClass, array $body): array
    {
        $plan = self::$plans[$entityClass] ??= self::buildPlan($entityClass);
        $out  = [];
        foreach ($plan as $key => $filter) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $out[$key] = $filter === null ? $body[$key] : $filter->apply($body[$key]);
        }
        return $out;
    }

    public static function registry(): FilterRegistry
    {
        return self::$registry ??= new FilterRegistry();
    }

    /** @internal — only for tests */
    public static function reset(): void
    {
        self::$plans    = [];
        self::$registry = null;
    }

    /** @return array<string, FilterInterface|null> */
    private static function buildPlan(string $entityClass): array
    {
        $ref  = new \ReflectionClass($entityClass);
        $plan = [];
        foreach ($ref->getProperties() as $prop) {
            $key   = Naming::toSnakeCase($prop->getName());
            $attrs = $prop->getAttributes(Clean::class);
            if ($attrs === []) {
                $plan[$key] = null;
                continue;
            }
            $plan[$key] = self::registry()->compile($attrs[0]->newInstance()->filters);
        }
        return $plan;
    }
}
