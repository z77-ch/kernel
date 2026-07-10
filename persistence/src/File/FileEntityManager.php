<?php

namespace Z77\Persistence\File;

use Z77\Core\Libraries\CacheManager,
    Z77\Shared\Attributes\Entity as EntityAttr,
    Z77\Persistence\Interface\EntityManagerInterface,
    Z77\Persistence\Interface\RepositoryInterface,
    Z77\Persistence\File\Repository\FileRepository,
    Z77\Persistence\File\Storage\CollectionStore,
    Z77\Persistence\File\Storage\DocumentStore,
    Z77\Persistence\File\Storage\FileStorage,
    Z77\Persistence\File\Storage\RecordStore
;

class FileEntityManager implements EntityManagerInterface
{
    private array $repositories = [];
    private array $pending = [];
    private bool $cacheInvalidationNeeded = false;

    public function __construct(
        private FileStorage $storage,
        private ?CacheManager $cacheManager = null
    ) {}

    public function getRepository(string $entityClass, EntityAttr $attr): RepositoryInterface
    {
        if (isset($this->repositories[$entityClass])) {
            return $this->repositories[$entityClass];
        }

        $store = $this->resolveStore($attr);
        $repo  = $this->resolveSpecific($entityClass, $store)
            ?? new FileRepository($entityClass, $store);

        return $this->repositories[$entityClass] = $repo;
    }

    public function persist(object $entity, EntityAttr $attr): void
    {
        $this->pending[] = ['entity' => $entity, 'attr' => $attr];

        if ($attr->invalidatesCache) {
            $this->cacheInvalidationNeeded = true;
        }
    }

    public function flush(): void
    {
        if (empty($this->pending)) {
            return;
        }

        // Group by storage path so a collection file is rewritten once per flush.
        $groups = [];
        foreach ($this->pending as $item) {
            $key = $item['attr']->getPath();
            $groups[$key]['attr']       = $item['attr'];
            $groups[$key]['entities'][] = $item['entity'];
        }

        foreach ($groups as $group) {
            $this->resolveStore($group['attr'])->persistAll($group['entities']);
        }

        $this->pending = [];

        if ($this->cacheInvalidationNeeded) {
            $this->invalidateCache();
            $this->cacheInvalidationNeeded = false;
        }
    }

    public function remove(object $entity, EntityAttr $attr): void
    {
        $this->resolveStore($attr)->delete($entity);

        if ($attr->invalidatesCache) {
            $this->invalidateCache();
        }
    }

    public function reorder(array $entities, EntityAttr $attr): void
    {
        // Collection-only: rewrite the file in the given entity order.
        $data = array_map(fn($e) => $e->mapToArray(), $entities);
        $this->storage->save($attr->getPath(), $data);

        if ($attr->invalidatesCache) {
            $this->invalidateCache();
        }
    }

    private function resolveStore(EntityAttr $attr): RecordStore
    {
        return $attr->perRecord
            ? new DocumentStore($this->storage, $attr)
            : new CollectionStore($this->storage, $attr);
    }

    private function invalidateCache(): void
    {
        if ($this->cacheManager === null) {
            return;
        }
        $this->cacheManager->clearAllApcu();
        $this->cacheManager->page()->clearAll();
    }

    private function resolveSpecific(string $entityClass, RecordStore $store): ?RepositoryInterface
    {
        $pos = strrpos($entityClass, '\\Entities\\');
        if ($pos === false) {
            return null;
        }

        $ns        = substr($entityClass, 0, $pos);
        $name      = basename(str_replace('\\', '/', $entityClass));
        $repoClass = $ns . '\\Repositories\\' . $name . 'Repository';

        if (class_exists($repoClass)) {
            return new $repoClass($entityClass, $store);
        }

        return null;
    }
}
