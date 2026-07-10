<?php

namespace Z77\Core\Services;

/**
 * AssetVersionService
 *
 * Provides per-asset version stamps for cache busting.
 *
 * Production: version is the source file's mtime — automatic invalidation
 * on every change without manual cache clearing on deploy. Each path is
 * stat()ed at most once per request (in-memory cache).
 *
 * Debug: version is the request time — captured once at construction so
 * every asset in the same request shares the same stamp (deterministic
 * cleanup, single versioned generation per request). The browser cannot
 * cache anything across requests because the stamp changes every time.
 */
final class AssetVersionService
{
    private array $cache = [];
    private int $requestTime;

    public function __construct(private bool $debug = false)
    {
        $this->requestTime = time();
    }

    /**
     * Returns the version stamp (Unix timestamp) for the given source file.
     * In debug mode: request time, identical for every asset in this request.
     * In production: filemtime, cached per source — stable until source changes.
     */
    public function version(string $sourcePath): int
    {
        if ($this->debug) {
            return $this->requestTime;
        }
        return $this->cache[$sourcePath] ??= (filemtime($sourcePath) ?: $this->requestTime);
    }
}
