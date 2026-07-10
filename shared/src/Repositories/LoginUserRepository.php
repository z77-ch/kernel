<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\LoginUser;

class LoginUserRepository extends FileRepository
{
    public function findByUsername(string $username): ?LoginUser
    {
        return $this->findOneBy(['username' => $username]);
    }
}
