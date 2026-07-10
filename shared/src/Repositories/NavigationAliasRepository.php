<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\NavigationAlias;

class NavigationAliasRepository extends FileRepository
{
    /** @return NavigationAlias[] */
    public function findByNavigationId(int $navigationId): array
    {
        return $this->findBy(['navigationId' => $navigationId]);
    }

    public function findByPath(string $path): ?NavigationAlias
    {
        return $this->findOneBy(['path' => $path]);
    }
}
