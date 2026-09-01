<?php

namespace Z77\Core\Libraries;

use Z77\Core\Libraries\Cache\DataCache,
    Z77\Core\Libraries\Cache\PageCache
;

/**
 * CacheManager
 *
 * Facade over the framework's cache pools. Holds one pool per use case and
 * delegates lifecycle calls (cache directory setup, deferred writes, debug
 * output) to whichever pool needs them.
 *
 * Pools:
 *   - data() — two-tier (local → APCu) for structured data
 *   - page() — file-only with per-entry TTL for fully rendered HTML
 *
 * Add new pools here when a new use case has different storage requirements
 * (e.g. binary asset cache, distributed shared cache). Do not extend the
 * existing pools beyond their charter.
 */
class CacheManager
{
    private DataCache $data;
    private PageCache $page;
    private string $absCacheDir = '';

    public function __construct()
    {
        $this->data = new DataCache();
        $this->page = new PageCache();
    }

    public function data(): DataCache
    {
        return $this->data;
    }

    public function page(): PageCache
    {
        return $this->page;
    }

    /**
     * Configures the cache directory once at boot and ensures it exists. Pools
     * receive the path and create their own subdirectories on demand.
     *
     * Takes an ABSOLUTE path — the caller (Bootstrap) owns the decision where the
     * cache lives, and since ADR-035 that decision is fixed to `ABS_VAR_PATH/cache`
     * rather than read from config. A relative path is rejected instead of being
     * silently anchored: an installation that still passes the pre-ADR-035
     * `lib/cache` must fail loudly, not resume caching into the shared store.
     */
    public function setCacheDir(string $absCacheDir): void
    {
        if ($this->absCacheDir) {
            throw new \RuntimeException('CacheManager: cache directory must only be set during bootstrap.');
        }

        $absCacheDir = rtrim(str_replace('\\', '/', $absCacheDir), '/');
        if (!str_starts_with($absCacheDir, '/') && !preg_match('~^[A-Za-z]:/~', $absCacheDir)) {
            throw new \RuntimeException(
                "CacheManager: setCacheDir() requires an absolute path, got '{$absCacheDir}'."
            );
        }

        if (!is_dir($absCacheDir) && !mkdir($absCacheDir, 0755, true) && !is_dir($absCacheDir)) {
            throw new \RuntimeException("CacheManager: could not create cache directory {$absCacheDir}");
        }

        $this->absCacheDir = $absCacheDir;
        $this->page->setCacheDir($absCacheDir);
        $this->data->setStampPath($absCacheDir . '/apcu.stamp');
    }

    /**
     * Persists deferred writes from pools that buffer them.
     * Called once at request shutdown by the Dispatcher.
     */
    public function flush(): void
    {
        $this->data->flush();
        // PageCache writes synchronously — no flush needed.
    }

    /**
     * Wipes this installation's APCu pool (in-process tiers included) and
     * advances the cross-process stamp so CLI and FPM pools invalidate each
     * other. Used at boot in DEBUG mode and on every invalidatesCache entity
     * write. Does not touch file caches.
     */
    public function clearAllApcu(): void
    {
        $this->data->clearAllApcu();
    }

    /**
     * Emits a debug snapshot of cache state via the global debug() helper.
     */
    public function debug(string $message = '', bool $limited = true): void
    {
        $this->data->debug($message, $limited);
    }
}
