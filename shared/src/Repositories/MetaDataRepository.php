<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\MetaData;

class MetaDataRepository extends FileRepository
{
    public function findByNavigationAndLanguage(int $navigationId, string $language): ?MetaData
    {
        return $this->findOneBy([
            'navigation_id' => $navigationId,
            'language'      => $language,
        ]);
    }
}
