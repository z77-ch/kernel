<?php

namespace Z77\Core\Libraries\Cache;

/**
 * PageIdentity
 *
 * Value object identifying a cached page by routing result, not by URL.
 * Two different URLs that resolve to the same (language, module, group, controller, action)
 * tuple share one cache entry — the cache key is the routing outcome.
 *
 * Used by PageCache for storage paths and by PageCachePolicy / editor code for
 * targeted invalidation. The components map directly to filesystem segments:
 *   cache/pages/{language}/{module}/{group}/{controller}/{action}.html
 *
 * Components are produced by Request parsing, which has already cleaned them
 * via StringCleaner (alpha / alphanumeric only) — no additional path-traversal
 * sanitization is needed here.
 */
final class PageIdentity
{
    public function __construct(
        public readonly string $language,
        public readonly string $module,
        public readonly string $group,
        public readonly string $controller,
        public readonly string $action,
    ) {
        // Defense in depth: Routing already cleans these via StringCleaner,
        // but the cache file path must never escape the cache root, even if a
        // future caller (editor, CLI tool) constructs identities directly.
        foreach ([
            'language'   => $language,
            'module'     => $module,
            'group'      => $group,
            'controller' => $controller,
            'action'     => $action,
        ] as $name => $value) {
            if ($value === ''
                || str_contains($value, '/')
                || str_contains($value, '\\')
                || str_contains($value, '..')
                || str_contains($value, "\0")
            ) {
                throw new \InvalidArgumentException(
                    "PageIdentity: '{$name}' contains illegal path characters or is empty: '{$value}'"
                );
            }
        }
    }
}
