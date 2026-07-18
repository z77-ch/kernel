<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\EmailFormSetting;

class EmailFormSettingRepository extends FileRepository
{
    public function findByFormKey(string $formKey): ?EmailFormSetting
    {
        return $this->findOneBy(['form_key' => $formKey]);
    }
}
