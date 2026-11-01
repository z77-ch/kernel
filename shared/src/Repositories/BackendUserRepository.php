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

    /** By recovery address, case-insensitive. '' finds nobody: most users may have no address. */
    public function findByEmail(string $email): ?BackendUser
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        foreach ($this->findAll() as $user) {
            if (strcasecmp($user->getEmail(), $email) === 0) {
                return $user;
            }
        }
        return null;
    }

    /**
     * What the «Passwort vergessen» form accepts: the recovery address, or the username in any
     * casing (usernames are unique case-insensitively, BackendUserValidator).
     */
    public function findByRecoveryIdentifier(string $identifier): ?BackendUser
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        foreach ($this->findAll() as $user) {
            if (strcasecmp($user->getUsername(), $identifier) === 0) {
                return $user;
            }
        }
        return $this->findByEmail($identifier);
    }
}
