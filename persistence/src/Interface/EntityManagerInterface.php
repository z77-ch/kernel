<?php
namespace Z77\Persistence\Interface;

use Z77\Shared\Attributes\Entity as EntityAttr;

interface EntityManagerInterface
{
    public function getRepository(string $entityClass, EntityAttr $attr): RepositoryInterface;
    public function persist(object $entity, EntityAttr $attr): void;
    public function flush(): void;
    public function remove(object $entity, EntityAttr $attr): void;
    public function reorder(array $entities, EntityAttr $attr): void;
}
