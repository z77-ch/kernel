<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\EmailDocumentSetting;

class EmailDocumentSettingRepository extends FileRepository
{
    public function findByKind(string $kind): ?EmailDocumentSetting
    {
        return $this->findOneBy(['kind' => $kind]);
    }
}
