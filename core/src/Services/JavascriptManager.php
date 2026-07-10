<?php

namespace Z77\Core\Services;

use Z77\Core\DI;

/**
 * JavascriptManager
 *
 * Resolves JS source files and creates versioned copies for cache busting.
 * Cleanup of obsolete versioned variants is delegated to AssetCleaner — the
 * single owner of versioned-asset deletion (also handles the race-window
 * protection: recently created files are never removed).
 *
 * Debug mode controls only which source variant is used:
 *   debug=true  → looks for `name.js`     (uncompressed)
 *   debug=false → looks for `name.min.js` (minified)
 *
 * Versioning is filemtime-based — independent of debug mode.
 */
final class JavascriptManager
{
    public function __construct(
        private AssetVersionService $assetVersion,
        private AssetCleaner $cleaner,
        private bool $debug = false
    ) {}

    /**
     * Returns the suffix used for minified JS source filenames.
     * Empty in debug mode, '.min' in production.
     * Public so LayoutManager can format error messages with the actual
     * filename it was trying to load.
     */
    public function minSuffix(): string
    {
        return $this->debug ? '' : '.min';
    }

    /**
     * Returns the path to a versioned JS file.
     * Uses the minified suffix (.min in production, empty in debug).
     * Creates a versioned copy if it does not yet exist. Removes obsolete
     * variants via AssetCleaner.
     *
     * debug:      js/main.js          → js/main_at-{mtime}.js
     * production: js/main.min.js      → js/main_at-{mtime}.min.js
     */
    public function getVersionedJs(string $baseName, string $nameSpace): string
    {
        $min = $this->minSuffix();

        $sourcePath = DI::getFileFinder()->getFirstAssetMatch(
            fileName: "js/{$baseName}{$min}.js",
            nameSpace: $nameSpace,
            throwError: true
        );

        $version  = $this->assetVersion->version($sourcePath);
        $dir      = str_replace('\\', '/', dirname($sourcePath));
        $fileBase = basename($sourcePath, $min . '.js');
        $target   = "{$dir}/{$fileBase}_at-{$version}{$min}.js";

        if (!is_file($target)) {
            $this->atomicCopy($sourcePath, $target);

            $sourceMap = $sourcePath . '.map';
            if (is_file($sourceMap) && !is_file($target . '.map')) {
                $this->atomicCopy($sourceMap, $target . '.map');
            }
        }

        $this->cleaner->cleanupVersionsFor($dir, $fileBase, 'js', $target);

        return $target;
    }

    /**
     * Atomic file copy: stage in same-directory temp file, then rename onto target.
     * On the same filesystem, rename() is atomic — concurrent readers see either
     * the old file or the fully-written new one, never a partial state. Prevents
     * race conditions when two parallel requests version the same asset.
     */
    private function atomicCopy(string $source, string $target): void
    {
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (!copy($source, $tmp)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to stage versioned JS file at '{$tmp}'.");
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to commit versioned JS file to '{$target}'.");
        }
    }
}
