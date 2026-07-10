<?php

namespace Z77\Shared\Traits;

use Z77\Shared\Libraries\Convention\Naming;

trait ArrayMappable
{
    public function mapFromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            $setter = 'set' . Naming::toCamelCase($key);
            if (method_exists($this, $setter)) {
                $this->$setter($value);
            }
        }
    }

    public function mapToArray(): array
    {
        $ref = new \ReflectionClass($this);
        $result = [];
        foreach ($ref->getProperties() as $prop) {
            $prop->setAccessible(true);
            $result[Naming::toSnakeCase($prop->getName())] = $prop->getValue($this);
        }
        return $result;
    }
}
