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
 */
class DataCache
{
    private array $localCache = [];
    private array $toCache = [];
    private array $debugStats = [];
    private int $defaultTTL = 31536000; // 1 year
    private string $poolPrefix = 'Z77-apcu-pool';

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
     * Full invalidation primitive: wipes the entire APCu cache (regardless of pool
     * prefix) AND the in-process tiers (local read cache + deferred writes). Used at
     * boot in DEBUG mode and on every entity write (FileEntityManager). The local tier
     * MUST be dropped too — otherwise a read-after-write in the SAME request (e.g.
     * granting an ACE and re-rendering effective rights) would return the stale value
     * the local cache still holds, since it is read before APCu.
     */
    public function clearAllApcu(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
        $this->localCache = [];
        $this->toCache    = [];
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
