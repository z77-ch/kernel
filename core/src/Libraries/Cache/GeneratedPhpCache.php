<?php

namespace Z77\Core\Libraries\Cache;

/**
 * The pool for GENERATED PHP FILES under `var/cache` — cache files that are
 * `include`d rather than read (ADR-039 decision 11): Doctrine's metadata and
 * query cache (`var/cache/doctrine/`, written by `symfony/cache`'s
 * `PhpFilesAdapter`), and whatever a later package compiles the same way.
 *
 * What makes these files a category of their own: an included file lives on
 * in OPcache after it was deleted from disk. With `validate_timestamps = 0`
 * — the setting a production host runs — a new file at the same path is
 * never re-read, so a stale metadata entry would be served until the next
 * OPcache restart. Deleting the directory is therefore only half of «Cache
 * leeren»; the other half is `opcache_invalidate($file, true)` for EVERY
 * file, which this pool does — BEFORE the file is deleted: OPcache resolves
 * the path it is given through `realpath()`, and a path that no longer
 * exists resolves to nothing (verified: invalidating after `unlink()` leaves
 * the compiled copy in place). Never `opcache_reset()`: on shared hosting
 * that empties every site's cache on the box.
 *
 * Invalidation reaches the CALLING process tree's OPcache only. From the
 * backend (web SAPI) that is the pool that matters; from the CLI (`z77-db
 * migrate`) it is the CLI's own, usually none — the web process notices the
 * deleted files through `validate_timestamps`, or through «Cache leeren»
 * (persistence-doctrine.md DOCTRINE-CACHE-001).
 *
 * The kernel owns the pool and the directory names, not the package that
 * writes into them: «Cache leeren» and the DEBUG toggle are backend features
 * that must work — as a no-op — on an installation WITHOUT the Doctrine
 * package (a directory that does not exist is nothing to clear). A writer
 * asks {@see dir()} for its directory instead of composing the path itself,
 * so the path exists in exactly one place (Rule 2).
 *
 * Release-local like everything under `var/cache` (ADR-035): `current` and
 * `next` compile their own files from their own code.
 */
class GeneratedPhpCache
{
    /**
     * The sub-directories of `var/cache` this pool clears. A new writer adds
     * its name here — the list is what «Cache leeren» works on, so a
     * directory not listed is a directory nobody clears.
     */
    public const DIRS = ['doctrine'];

    private string $absCacheDir = '';

    /** Set once at boot by `CacheManager::setCacheDir()`; the absolute `var/cache`. */
    public function setCacheDir(string $absCacheDir): void
    {
        $this->absCacheDir = rtrim(str_replace('\\', '/', $absCacheDir), '/');
    }

    /**
     * The absolute directory a writer compiles into — `var/cache/{$name}`.
     * Not created here: the writer creates it on first write (ADR-034: every
     * disposable directory creates itself), and «Cache leeren» removes it
     * whole.
     *
     * @throws \InvalidArgumentException for a name not listed in DIRS — the pool would never clear it
     * @throws \RuntimeException         before the cache directory is configured
     */
    public function dir(string $name): string
    {
        if (!in_array($name, self::DIRS, true)) {
            throw new \InvalidArgumentException(
                "GeneratedPhpCache: '{$name}' is not a registered directory — add it to " . self::class . '::DIRS '
                . 'so «Cache leeren» clears it.'
            );
        }
        if ($this->absCacheDir === '') {
            throw new \RuntimeException('GeneratedPhpCache: the cache directory is configured at boot (CacheManager::setCacheDir()).');
        }

        return $this->absCacheDir . '/' . $name;
    }

    /**
     * Invalidates every file of every registered directory in OPcache, then
     * deletes the directories. Absent directories are skipped — the normal
     * case on an installation without a compiling package.
     *
     * Safe next to a concurrent writer (a web request compiling metadata
     * while the backend clears): the directory is moved aside with one
     * atomic `rename()` after the invalidation pass, so a writer that
     * re-creates `var/cache/doctrine/…` meanwhile writes into a fresh tree
     * and never into the one being deleted; a file that vanished between
     * listing and unlinking is not an error. Invalidation uses the ORIGINAL
     * paths — the ones the writer `include`s — before anything moves.
     *
     * @return list<string> the files that were invalidated and deleted (original absolute paths)
     * @throws \RuntimeException when a registered directory is a symlink (never delete outside
     *                           `var/cache`), or when a file or directory is still there after
     *                           the attempt to remove it (permissions — a half-cleared cache
     *                           would serve old and new entries side by side)
     */
    public function clearAll(): array
    {
        if ($this->absCacheDir === '') {
            return [];
        }

        $deleted = [];
        foreach (self::DIRS as $name) {
            $dir = $this->absCacheDir . '/' . $name;
            if (is_link($dir)) {
                throw new \RuntimeException("GeneratedPhpCache: {$dir} is a symlink — refusing to clear through it.");
            }
            if (!is_dir($dir)) {
                continue;
            }

            $this->invalidateTree($dir, $deleted);

            $aside = $dir . '.clearing-' . bin2hex(random_bytes(4));
            $this->removeTree(@rename($dir, $aside) ? $aside : $dir);
        }

        return $deleted;
    }

    /**
     * First pass: every file's compiled copy is dropped from OPcache while
     * the file still exists at that path. `opcache_invalidate()` returns
     * false when OPcache is off (CLI) — nothing to invalidate then.
     *
     * @param list<string> $files
     */
    private function invalidateTree(string $dir, array &$files): void
    {
        foreach ($this->entries($dir) as $path) {
            if (is_dir($path) && !is_link($path)) {
                $this->invalidateTree($path, $files);
                continue;
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            $files[] = $path;
        }
    }

    /** Second pass: delete. A path that is already gone is fine; one that stays is not. */
    private function removeTree(string $dir): void
    {
        foreach ($this->entries($dir) as $path) {
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
                continue;
            }
            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException("GeneratedPhpCache: cannot delete {$path}");
            }
        }
        if (!@rmdir($dir) && is_dir($dir)) {
            throw new \RuntimeException("GeneratedPhpCache: cannot remove {$dir}");
        }
    }

    /** @return list<string> absolute paths of the directory's entries; empty when it is gone */
    private function entries(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $paths = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $paths[] = $dir . '/' . $entry;
            }
        }

        return $paths;
    }
}
