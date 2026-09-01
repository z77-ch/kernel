<?php

namespace Z77\Core\Libraries\Cache;

/**
 * DataCache
 *
 * Two-tier cache for structured data (arrays, scalars).
 *
 * Read order:  local request cache → APCu
 * Write paths: in-memory always; APCu on flush() if marked persistent (cachePersist)
 *
 * Not for HTML output — see PageCache for that. JSON encoding makes raw HTML bloated
 * and unreadable, and APCu's small memory pool would be evicted by even a few pages.
 *
 * The pool prefix is NAMESPACED PER INSTALLATION (hash of ABS_BASE_PATH):
 * APCu is shared across every vhost of a PHP pool, so two z77 installations
 * on the same hosting account would otherwise read each other's entries —
 * observed live 2026-08-06 (cyon: axo3.ch served zihlundsee.ch's cached
 * layout/config the moment both ran with debug off). Same reason
 * clearAllApcu() deletes only this pool's keys, never the whole APCu.
 *
 * CROSS-PROCESS INVALIDATION (CACHE-CLI-001): APCu is per process tree. A
 * CLI run (cron, z77-run, installer) has its own pool and cannot reach the
 * FPM pool with apcu_delete(). So every clearAllApcu() also touches a stamp
 * file on disk (var/cache/apcu.stamp), and each process compares that
 * file's mtime with the mtime it stored in ITS pool the last time it
 * synced — a newer file means "somebody else invalidated": wipe and resync.
 * One filemtime() per request.
 *
 * The stamp is RELEASE-LOCAL since ADR-035, and that is correct rather than a
 * compromise: web and cron resolve the same `ABS_BASE_PATH` (both go through
 * the symlink, both end at `releases/<name>`), so they share the stamp that
 * matters — while a cron running on the staging release no longer wipes
 * production's pool. It requires the cron entry to go THROUGH `current`, which
 * release-structure.md makes binding for other reasons already.
 */
class DataCache
{
    private array $localCache = [];
    private array $toCache = [];
    private array $debugStats = [];
    private int $defaultTTL = 31536000; // 1 year
    private string $poolPrefix;
    private ?string $stampPath = null;

    public function __construct(?string $basePath = null)
    {
        $this->poolPrefix = self::poolPrefixFor(
            $basePath ?? (defined('ABS_BASE_PATH') ? ABS_BASE_PATH : '')
        );
    }

    /**
     * Installation-scoped pool prefix: first 12 hex chars of md5(basePath).
     * Hashing the FULL path matters — sibling installations share long path
     * prefixes (/home/{account}/public_html/…), so a substring of the path
     * itself would not separate them.
     */
    public static function poolPrefixFor(string $basePath): string
    {
        return 'Z77-apcu-pool-' . substr(md5($basePath), 0, 12);
    }

    /**
     * Builds a stable, sanitized cache key from a class name and arbitrary components.
     */
    public function generateKey(string $className, array $components = []): string
    {
        $safeComponents = array_map(
            fn($c) => preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', (string) $c),
            $components
        );
        $key = "{$this->poolPrefix}::{$className}";
        if (!empty($safeComponents)) {
            $key .= '::' . implode('::', $safeComponents);
        }
        return $key;
    }

    /**
     * Stores a value in the local request cache.
     * Optionally marks it for persistent write to APCu at flush().
     */
    public function set(
        string $className,
        array $components,
        $value,
        bool $cachePersist = false,
        ?int $ttl = null,
    ): void {
        $key = $this->generateKey($className, $components);
        $this->localCache[$key] = $value;

        if ($cachePersist) {
            $this->toCache[$key] = ['ttl' => $ttl ?? $this->defaultTTL];
        }
    }

    /**
     * Reads a value from local cache, then APCu. Returns null on miss.
     */
    public function get(string $className, array $components)
    {
        $key = $this->generateKey($className, $components);

        // 1. Local request cache
        if (isset($this->localCache[$key])) {
            if (defined('DEBUG') && DEBUG) {
                $this->incrementDebug($key, 'local');
            }
            return $this->localCache[$key];
        }

        // 2. APCu
        if (function_exists('apcu_fetch')) {
            $value = apcu_fetch($key, $success);
            if ($success && $value !== false) {
                $this->localCache[$key] = $value;
                if (defined('DEBUG') && DEBUG) {
                    $this->incrementDebug($key, 'apcu');
                }
                return $value;
            }
        }

        // 3. Miss
        if (defined('DEBUG') && DEBUG) {
            $this->incrementDebug($key, 'miss');
        }
        return null;
    }

    /**
     * Persists every value marked at set() time to APCu.
     * Called once at request shutdown.
     */
    public function flush(): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }
        foreach ($this->toCache as $key => $entry) {
            if (!isset($this->localCache[$key])) {
                continue;
            }
            apcu_store($key, $this->localCache[$key], $entry['ttl'] ?? $this->defaultTTL);
        }
    }

    /**
     * Removes APCu entries owned by this pool. Without a class prefix, every key
     * starting with the pool prefix is deleted.
     */
    public function clear(?string $classPrefix = null): void
    {
        if (!function_exists('apcu_delete') || !function_exists('apcu_cache_info')) {
            return;
        }
        $info = apcu_cache_info();
        if (empty($info['cache_list'])) {
            return;
        }

        $prefix = "{$this->poolPrefix}::";
        if ($classPrefix) {
            $prefix .= "{$classPrefix}::";
        }

        foreach ($info['cache_list'] as $entry) {
            $key = $entry['info'] ?? '';
            if (str_starts_with($key, $prefix)) {
                apcu_delete($key);
            }
        }
    }

    /**
     * Registers the cross-process invalidation stamp and syncs against it
     * immediately: a stamp newer than the one this pool remembers means
     * another process (typically a CLI job) invalidated since this pool was
     * filled — wipe it. Called once at boot via CacheManager::setCacheDir().
     */
    public function setStampPath(string $path): void
    {
        $this->stampPath = $path;
        $this->syncWithStamp();
    }

    private function stampKey(): string
    {
        return "{$this->poolPrefix}::__stamp";
    }

    private function stampMtime(): int
    {
        clearstatcache(true, $this->stampPath);
        $mtime = @filemtime($this->stampPath);
        return $mtime === false ? 0 : $mtime;
    }

    private function syncWithStamp(): void
    {
        if ($this->stampPath === null || !function_exists('apcu_fetch')) {
            return;
        }
        $onDisk = $this->stampMtime();
        $known  = apcu_fetch($this->stampKey(), $success);
        if ($success && (int) $known === $onDisk) {
            return;
        }
        $this->clear();
        $this->localCache = [];
        $this->toCache    = [];
        apcu_store($this->stampKey(), $onDisk, 0);
    }

    /**
     * Advances the stamp so every OTHER pool wipes on its next sync. Strictly
     * monotonic: filemtime has one-second resolution, so a touch within the
     * same second as the last sync would go unnoticed — hence max(now, old+1).
     * Our own pool is re-marked as in sync (it was just wiped by the caller).
     */
    private function touchStamp(): void
    {
        if ($this->stampPath === null) {
            return;
        }
        $next = max(time(), $this->stampMtime() + 1);
        if (!@touch($this->stampPath, $next)) {
            return; // cache dir unwritable — same failure class as PageCache writes, must not kill the request
        }
        if (function_exists('apcu_store')) {
            apcu_store($this->stampKey(), $next, 0);
        }
    }

    /**
     * Full invalidation primitive for THIS INSTALLATION: drops every APCu key
     * of this pool prefix AND the in-process tiers (local read cache +
     * deferred writes). Used at boot in DEBUG mode and on every entity write
     * (FileEntityManager). The local tier MUST be dropped too — otherwise a
     * read-after-write in the SAME request (e.g. granting an ACE and
     * re-rendering effective rights) would return the stale value the local
     * cache still holds, since it is read before APCu.
     *
     * Deliberately NOT apcu_clear_cache(): APCu is shared across the PHP
     * pool, a full wipe would evict every co-hosted installation's cache on
     * each entity write / debug boot (the pre-2026-08-06 behaviour).
     */
    public function clearAllApcu(): void
    {
        $this->clear();
        $this->localCache = [];
        $this->toCache    = [];
        $this->touchStamp();
    }

    /**
     * Emits a debug snapshot of APCu state and per-key local/apcu/miss counters
     * via the global debug() helper.
     */
    public function debug(string $message = '', bool $limited = true): void
    {
        if (!function_exists('apcu_cache_info') || !function_exists('apcu_sma_info')) {
            debug('APCu is not installed or enabled.');
            return;
        }

        $cacheInfo = apcu_cache_info($limited);
        $smaInfo   = apcu_sma_info(true);

        $numEntries = $cacheInfo['num_entries'] ?? 0;
        $memSize    = $smaInfo['seg_size']      ?? 0;
        $memFree    = $smaInfo['avail_mem']     ?? 0;
        $memUsed    = $memSize - $memFree;
        $startTime  = $cacheInfo['start_time']  ?? 0;
        $liveTime   = time() - $startTime;

        $keys = [];
        foreach ($cacheInfo['cache_list'] ?? [] as $entry) {
            $keys[] = [
                'key'         => $entry['info']     ?? '',
                'filename'    => $entry['filename'] ?? '',
                'type'        => $entry['type']     ?? '',
                'mem_size'    => $entry['mem_size'] ?? 0,
                'num_hits'    => $entry['num_hits'] ?? 0,
                'creation_ts' => date('Y-m-d H:i:s', ($entry['creation_time'] ?? 0)),
                'access_ts'   => $entry['access_time'] ?? 0,
                'value'       => apcu_fetch($entry['info']) ?? 'nope',
            ];
        }

        $debugStats = [];
        foreach ($this->debugStats as $key => $stats) {
            $debugStats[] = sprintf(
                '%-40s | local: %3d | apcu: %3d | miss: %3d',
                $key,
                $stats['local'] ?? 0,
                $stats['apcu']  ?? 0,
                $stats['miss']  ?? 0
            );
        }

        debug($message, [
            'debugStats' => $debugStats,
            'summary'    => [
                'start_time'   => date('Y-m-d H:i:s', $startTime),
                'live_time'    => $liveTime,
                'total_keys'   => $numEntries,
                'total_memory' => $memSize,
                'used_memory'  => $memUsed,
                'free_memory'  => $memFree,
            ],
            'keys' => $keys,
        ]);
    }

    private function incrementDebug(string $key, string $source): void
    {
        if (!isset($this->debugStats[$key])) {
            $this->debugStats[$key] = ['local' => 0, 'apcu' => 0, 'file' => 0, 'miss' => 0];
        }
        $this->debugStats[$key][$source]++;
    }
}
