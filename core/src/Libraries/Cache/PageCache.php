<?php

namespace Z77\Core\Libraries\Cache;

use Z77\Core\Http\Response\HtmlResponse;

/**
 * PageCache
 *
 * Stores fully rendered HTML pages on disk for replay on subsequent requests.
 * Each entry has an absolute expiry timestamp written as the first line of the
 * cache file; reads check it and return null on expiry.
 *
 * File path scheme: {cacheDir}/pages/{language}/{module}/{group}/{controller}/{action}.html
 * The structured path (instead of a hash) allows targeted invalidation of all
 * pages under a module, group, or controller without scanning every file.
 *
 * The store talks to its caller in HtmlResponse: get() returns a ready-to-send
 * response on hit, set() takes the live response object and persists its HTML.
 * This keeps the Dispatcher agnostic — it never sees the raw cached string and
 * uses the same response type whether the body came from cache or controller.
 *
 * Writes are synchronous — the page is large, batching gives no benefit, and
 * a deferred write that loses the file on a late crash would be worse than a
 * cache miss.
 *
 * Skip policy (no cache for POST, authenticated users, query strings, etc.)
 * is the caller's responsibility. PageCache itself is a dumb store.
 */
class PageCache
{
    private const SUB_DIR    = 'pages';
    private const HEADER_PFX = '<!--z77-cache: expires=';
    private const HEADER_SFX = '-->';

    private string $absCacheDir = '';

    public function setCacheDir(string $absCacheDir): void
    {
        $this->absCacheDir = $absCacheDir;
    }

    /**
     * Returns the file mtime of a fresh cache entry for $identity, or null if
     * the file is missing or expired. Used by PageCachePolicy to compare
     * against the request's If-None-Match header without reading the body.
     * Stale files are deleted lazily here too.
     */
    public function getMtime(PageIdentity $identity): ?int
    {
        $file = $this->pathFor($identity);
        if (!is_readable($file)) {
            return null;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return null;
        }
        $headerLine = fgets($handle);
        fclose($handle);
        if ($headerLine === false) {
            return null;
        }

        $expires = $this->parseExpiry(rtrim($headerLine, "\r\n"));
        if ($expires === null) {
            return null;
        }
        if ($expires < time()) {
            @unlink($file);
            return null;
        }

        $mtime = filemtime($file);
        return $mtime === false ? null : $mtime;
    }

    /**
     * Returns a ready-to-send response if a fresh entry exists for $identity.
     * Validates freshness via getMtime(); on hit, reads the body past the
     * header line. Stale files are deleted by getMtime().
     */
    public function get(PageIdentity $identity): ?HtmlResponse
    {
        $mtime = $this->getMtime($identity);
        if ($mtime === null) {
            return null;
        }

        $file    = $this->pathFor($identity);
        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            return null;
        }

        $newlinePos = strpos($content, "\n");
        if ($newlinePos === false) {
            return null;
        }

        return HtmlResponse::fromCache(
            substr($content, $newlinePos + 1),
            $mtime
        );
    }

    /**
     * Persists the response's HTML for $identity with an absolute expiry of
     * now + $ttl seconds. Creates the directory tree on demand. Overwrites
     * any existing entry for the same identity. Returns the file mtime
     * (= ETag for the freshly written entry).
     *
     * Writes are atomic: the payload is staged in a temp file in the same
     * directory and then renamed onto the target. POSIX guarantees a single
     * filesystem rename is atomic; concurrent readers always see either the
     * old file or the fully-written new one, never a partially written one.
     */
    public function set(PageIdentity $identity, HtmlResponse $response, int $ttl): int
    {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException("PageCache::set() requires ttl > 0, got {$ttl}");
        }

        $file = $this->pathFor($identity);
        $dir  = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("PageCache: could not create directory {$dir}");
        }

        $header  = self::HEADER_PFX . (time() + $ttl) . self::HEADER_SFX;
        $payload = $header . "\n" . $response->getHtml();

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload) === false) {
            throw new \RuntimeException("PageCache: could not write {$tmp}");
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("PageCache: could not rename {$tmp} to {$file}");
        }

        clearstatcache(true, $file);
        $mtime = filemtime($file);
        if ($mtime === false) {
            throw new \RuntimeException("PageCache: could not stat {$file} after write");
        }
        return $mtime;
    }

    /**
     * Removes the cache entry for the given page if it exists.
     */
    public function invalidate(PageIdentity $identity): void
    {
        $file = $this->pathFor($identity);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Removes every cached page under {module}/{group}/{controller}, across all languages.
     * Called by editor code when a controller's content changes.
     */
    public function invalidateController(string $module, string $group, string $controller): void
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return;
        }
        foreach (scandir($root) ?: [] as $lang) {
            if ($lang === '.' || $lang === '..') {
                continue;
            }
            $controllerDir = $root . '/' . $lang . '/' . $module . '/' . $group . '/' . $controller;
            if (is_dir($controllerDir)) {
                $this->rrmdir($controllerDir);
            }
        }
    }

    /**
     * Removes every cached page under {module}/{group}, across all languages and controllers.
     * Called by editor code when a navigation group is restructured.
     */
    public function invalidateGroup(string $module, string $group): void
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return;
        }
        foreach (scandir($root) ?: [] as $lang) {
            if ($lang === '.' || $lang === '..') {
                continue;
            }
            $groupDir = $root . '/' . $lang . '/' . $module . '/' . $group;
            if (is_dir($groupDir)) {
                $this->rrmdir($groupDir);
            }
        }
    }

    /**
     * Removes every cached page under {module}, across all languages and controllers.
     * Called by editor code when a module's cache config or structure changes.
     */
    public function invalidateModule(string $module): void
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return;
        }
        foreach (scandir($root) ?: [] as $lang) {
            if ($lang === '.' || $lang === '..') {
                continue;
            }
            $moduleDir = $root . '/' . $lang . '/' . $module;
            if (is_dir($moduleDir)) {
                $this->rrmdir($moduleDir);
            }
        }
    }

    /**
     * Removes every cached page. Used during full cache wipes (e.g. deploys).
     */
    public function clearAll(): void
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return;
        }
        $this->rrmdir($root, keepRoot: true);
    }

    private function pathFor(PageIdentity $id): string
    {
        return $this->root()
            . '/' . $id->language
            . '/' . $id->module
            . '/' . $id->group
            . '/' . $id->controller
            . '/' . $id->action . '.html';
    }

    private function root(): string
    {
        return rtrim($this->absCacheDir, '/') . '/' . self::SUB_DIR;
    }

    private function parseExpiry(string $header): ?int
    {
        if (!str_starts_with($header, self::HEADER_PFX) || !str_ends_with($header, self::HEADER_SFX)) {
            return null;
        }
        $raw = substr(
            $header,
            strlen(self::HEADER_PFX),
            -strlen(self::HEADER_SFX)
        );
        if (!ctype_digit($raw)) {
            return null;
        }
        return (int) $raw;
    }

    private function rrmdir(string $path, bool $keepRoot = false): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        if (!$keepRoot) {
            @rmdir($path);
        }
    }
}
