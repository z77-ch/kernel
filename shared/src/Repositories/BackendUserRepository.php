<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\BackendUser;

class BackendUserRepository extends FileRepository
{
    public function findByUsername(string $username): ?BackendUser
    {
        return $this->findOneBy(['username' => $username]);
    }
}
