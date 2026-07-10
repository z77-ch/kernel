<?php

namespace Z77\Persistence\Resolver;

use Z77\Persistence\Interface\EntityManagerInterface,
    Z77\Persistence\Interface\RepositoryInterface,
    Z77\Persistence\Resolver\DataSourceResolver,
    Z77\Shared\Libraries\Convention\Naming
;

final class UnifiedEntityManager
{
    private array $managerCache = [];

    public function __construct(
        private DataSourceResolver $resolver)
    {}

    public function getRepository(string $className): RepositoryInterface
    {
        return $this->resolveManager($className)->getRepository($className, $this->resolver->resolveEntity($className));
    }

    public function persist(object $entity): void
    {
        $attr = $this->resolver->resolveEntity($entity::class);
        $this->resolveManager($entity::class)->persist($entity, $attr);
    }

    public function flush(): void
    {
        foreach ($this->managerCache as $manager) {
            $manager->flush();
        }
    }

    public function remove(object $entity): void
    {
        $attr = $this->resolver->resolveEntity($entity::class);
        $this->resolveManager($entity::class)->remove($entity, $attr);
    }

    public function reorder(array $entities): void
    {
        if (empty($entities)) {
            return;
        }
        $attr = $this->resolver->resolveEntity($entities[0]::class);
        $this->resolveManager($entities[0]::class)->reorder($entities, $attr);
    }

    private function resolveManager(string $className): EntityManagerInterface
    {
        $driver = $this->resolver->resolveEntity($className)->driver;

        if (!isset($this->managerCache[$driver])) {
            $this->managerCache[$driver] = $this->bootManager($driver);
        }

        return $this->managerCache[$driver];
    }

    private function bootManager(string $driver): EntityManagerInterface
    {
        $bootstrapClass = Naming::toNamespaceString(
            ['Z77', 'Persistence', $driver]
        ).'Bootstrap';

        return (new $bootstrapClass())->getEntityManager();
    }
}
