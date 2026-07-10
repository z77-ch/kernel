<?php

namespace Z77\Persistence\File;

use Z77\Core\DI,
    Z77\Persistence\Interface\EntityManagerInterface
;

class Bootstrap
{
    private FileEntityManager $entityManager;

    public function __construct()
    {
        $this->entityManager = new FileEntityManager(
            new Storage\FileStorage(),
            DI::getCacheManager()
        );
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
