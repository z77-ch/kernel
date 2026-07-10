<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\Content;

class ContentRepository extends FileRepository
{
    /**
     * Load one content document by its natural key (slug + language).
     * Covers the store's keyFields → resolves to a single file (O(1)).
     */
    public function findBySlug(string $slug, string $language): ?Content
    {
        return $this->findOneBy(['slug' => $slug, 'language' => $language]);
    }
}
