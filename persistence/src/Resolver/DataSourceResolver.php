<?php
namespace Z77\Persistence\Resolver;

use Z77\Shared\Attributes\Entity as EntityAttr;

class DataSourceResolver
{
    private array $cache = [];

    public function __construct(
        private array $driverMap // e.g. ['file' => 'File', 'doctrine' => 'Doctrine']
    ) {}

    public function resolveEntity(string $className): EntityAttr
    {
        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        $ref = new \ReflectionClass($className);
        $attrs = $ref->getAttributes(EntityAttr::class);

        if (!$attrs) {
            throw new \RuntimeException("Class $className is not a Z77 Shared Entity.");
        }
        // nimm das erste vorkommende Attribut in der Entity
        $attr = $attrs[0]->newInstance();
        $driverName = $attr->driverName ?? null;

        if (!isset($this->driverMap[$driverName])) {
            throw new \RuntimeException("No EntityManager registered for driver '$driverName'");
        }
        $attr->driver = $this->driverMap[$driverName];

        $this->cache[$className] = $attr;

        return $attr;
    }
}
