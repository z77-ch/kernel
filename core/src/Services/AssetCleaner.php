<?php

namespace Z77\Core\Services;

use Z77\Core\DI;

/**
 * AssetCleaner
 *
 * Single owner of versioned-asset cleanup. Two entry points:
 *
 *   cleanupVersionsFor() — called by StylesheetManager/JavascriptManager after
 *                          generating a new version, to remove obsolete variants
 *                          for that one asset (e.g. core_at-T1.min.js when
 *                          core_at-T2.min.js was just created).
 *
 *   clearAll()           — called by the backend "Clear cache" action to remove
 *                          versioned assets across every registered asset path.
 *
 * Both honour the same race-protection rule: files younger than
 * KEEP_RECENT_SECONDS are never deleted. This guards against the window between
 * "request A received HTML pointing to core_at-T1.min.js" and "request A fetches
 * the JS file" — a parallel cleanup must not remove the file mid-flight.
 */
final class AssetCleaner
{
    /**
     * Files newer than this are never deleted. Protects parallel browser
     * requests that may still resolve a recently-generated URL.
     */
    private const KEEP_RECENT_SECONDS = 30;

    /**
     * Pattern for versioned asset files. Matches names like:
     *   core_at-1747856392.min.js
     *   core_at-1747856392.min.js.map
     *   base_at-1747856392.css
     *   base_at-1747856392.css.map
     */
    private const VERSIONED_PATTERN = '/_at-\d+(?:\.min)?\.(?:css|js)(?:\.map)?$/';

    /**
     * Removes obsolete versioned variants for a single asset's basename.
     * Keeps $keepTarget (the just-generated current version) and any file
     * younger than KEEP_RECENT_SECONDS.
     *
     * When a versioned file is deleted, its companion source map (same path
     * with appended ".map") is removed too. The map is silently skipped if
     * absent.
     */
    public function cleanupVersionsFor(
        string $dir,
        string $fileBase,
        string $extension,
        string $keepTarget
    ): void {
        $now   = time();
        $paths = glob("{$dir}/{$fileBase}_at-*.{$extension}") ?: [];

        foreach ($paths as $path) {
            if ($path === $keepTarget) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false && ($now - $mtime) < self::KEEP_RECENT_SECONDS) {
                continue;
            }
            @unlink($path);
            @unlink($path . '.map');
        }
    }

    /**
     * Removes every versioned asset across all registered asset paths,
     * skipping files younger than KEEP_RECENT_SECONDS.
     * Returns the number of files actually removed.
     */
    public function clearAll(): int
    {
        $now   = time();
        $count = 0;
        $seen  = [];

        foreach (DI::getFileFinder()->getAllNamespaces() as $paths) {
            foreach ($paths['assetPaths'] ?? [] as $assetRoot) {
                $absRoot = str_replace('\\', '/', rtrim($assetRoot, '/\\'));
                if ($absRoot === '' || isset($seen[$absRoot])) {
                    continue;
                }
                $seen[$absRoot] = true;
                $count += $this->deleteVersionedIn($absRoot, $now);
            }
        }

        return $count;
    }

    private function deleteVersionedIn(string $absPath, int $now): int
    {
        if (!is_dir($absPath)) {
            return 0;
        }

        $count    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (!preg_match(self::VERSIONED_PATTERN, $file->getFilename())) {
                continue;
            }
            if (($now - $file->getMTime()) < self::KEEP_RECENT_SECONDS) {
                continue;
            }
            if (@unlink($file->getPathname())) {
                $count++;
            }
        }

        return $count;
    }
}
