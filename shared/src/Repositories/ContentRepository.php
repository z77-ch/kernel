<?php

namespace Z77\Shared\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Shared\Entities\Content;

class ContentRepository extends FileRepository
{
    /**
     * Load one content document by its natural key (slug + language + variant).
     * Covers the store's keyFields → resolves to a single file (O(1)).
     * $variant '' (the default) is the live copy.
     */
    public function findBySlug(string $slug, string $language, string $variant = ''): ?Content
    {
        return $this->findOneBy(['slug' => $slug, 'language' => $language, 'variant' => $variant]);
    }

    /**
     * All documents of one variant set, every language. Scans the directory —
     * backend only (publish, list), never on a page render.
     * @return Content[]
     */
    public function findByVariant(string $variant): array
    {
        if ($variant === '') {
            return [];
        }

        return $this->findBy(['variant' => $variant]);
    }

    /**
     * The keys of all variant sets present, sorted.
     * @return string[]
     */
    public function variantKeys(): array
    {
        $keys = [];
        foreach ($this->findAll() as $content) {
            if (!$content->isLive()) {
                $keys[$content->getVariant()] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }
}
