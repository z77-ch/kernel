<?php
namespace Z77\Persistence\Interface;

interface RepositoryInterface
{
    public function find(int|string $id): ?object;
    public function findAll(): array;
    public function findBy(array $criteria): array;
    public function findOneBy(array $criteria): ?object;
}
