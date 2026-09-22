<?php

namespace Z77\Persistence\Resolver;

use Z77\Persistence\Interface\EntityManagerInterface,
    Z77\Persistence\Interface\RepositoryInterface,
    Z77\Persistence\Interface\TransactionInterface,
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

    /**
     * The transaction port of the driver that stores $className (ADR-039
     * decision 10): resolved from an entity class so the backend stays a
     * property of `#[Entity]`. The File driver refuses it — a use case that
     * must be atomic writes to one driver only (ARCH-A007).
     */
    public function getTransaction(string $className): TransactionInterface
    {
        return $this->resolveManager($className)->getTransaction();
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

        // A driver named in the map need not be installed (ADR-039 decision 2):
        // the Doctrine driver is its own package. Fail here, at the first entity
        // of that driver, with the package named — not with a bare "class not
        // found" from somewhere inside the resolver.
        if (!class_exists($bootstrapClass)) {
            throw new \RuntimeException(
                "Persistence driver '{$driver}' is not installed: {$bootstrapClass} not found — "
                . "require the package that provides it (z77/persistence-doctrine for the Doctrine driver)."
            );
        }

        return (new $bootstrapClass())->getEntityManager();
    }
}
