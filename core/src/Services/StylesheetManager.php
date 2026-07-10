<?php

namespace Z77\Core\Services;

use Z77\Core\DI;

/**
 * StylesheetManager
 *
 * Resolves CSS source files and creates versioned copies for cache busting.
 * Cleanup of obsolete versioned variants is delegated to AssetCleaner — the
 * single owner of versioned-asset deletion (also handles the race-window
 * protection: recently created files are never removed).
 *
 * Versioning is filemtime-based — automatic invalidation when the source
 * file changes. Generated CSS (createCss) uses an external version stamp
 * supplied by the caller (e.g. max(updatedAt) of driving entities).
 */
final class StylesheetManager
{
    public function __construct(
        private AssetVersionService $assetVersion,
        private AssetCleaner $cleaner
    ) {}

    /**
     * Returns the path to a versioned CSS file.
     * Version is the source file's mtime — automatic cache busting on change.
     * Creates a versioned copy if it does not yet exist. Removes obsolete
     * variants via AssetCleaner.
     *
     * Source:    css/{name}.css
     * Versioned: css/{name}_at-{mtime}.css
     */
    public function getVersionedCss(string $baseName, string $nameSpace): string
    {
        $sourcePath = DI::getFileFinder()->getFirstAssetMatch(
            fileName: "css/{$baseName}.css",
            nameSpace: $nameSpace,
            throwError: true
        );

        $version  = $this->assetVersion->version($sourcePath);
        $dir      = str_replace('\\', '/', dirname($sourcePath));
        $fileBase = basename($sourcePath, '.css');
        $target   = "{$dir}/{$fileBase}_at-{$version}.css";

        if (is_file($target)) {
            return $target;
        }

        $this->atomicCopy($sourcePath, $target);

        $sourceMap = $sourcePath . '.map';
        if (is_file($sourceMap) && !is_file($target . '.map')) {
            $this->atomicCopy($sourceMap, $target . '.map');
        }

        $this->cleaner->cleanupVersionsFor($dir, $fileBase, 'css', $target);

        return $target;
    }

    /**
     * Generates a CSS file from a PHP template and data, versioned by an external timestamp.
     *
     * Unlike getVersionedCss() (mtime of a static file), the version here comes from
     * outside — typically max(updatedAt) of the entities driving the CSS (e.g. slider images).
     * If the versioned file already exists, it is returned immediately without re-rendering.
     * Old versioned files for this name are removed via AssetCleaner on each regeneration.
     *
     * Template: {tplDir}/{controller}/css/{template}.css.tpl.php (resolved via FileFinder for $nameSpace)
     * Output:   {first assetPath}/css/{name}_at-{version}.css
     */
    public function createCss(
        string $name,
        string $nameSpace,
        string $template,
        array  $data,
        int    $version
    ): string {
        $basePaths = DI::getFileFinder()->getBasePaths($nameSpace, 'assetPaths');
        if (empty($basePaths)) {
            throw new \RuntimeException("No asset paths registered for namespace '{$nameSpace}'.");
        }

        $dir    = str_replace('\\', '/', rtrim($basePaths[0], '/\\')) . '/css';
        $target = "{$dir}/{$name}_at-{$version}.css";

        if (is_file($target)) {
            return $target;
        }

        $tplPath = DI::getFileFinder()->getFirstTplMatch(
            "{$template}.css.tpl.php",
            $nameSpace,
            throwError: true
        );

        $css = (static function (string $tplPath, array $data): string {
            extract($data);
            ob_start();
            include $tplPath;
            return (string) ob_get_clean();
        })($tplPath, $data);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->atomicWrite($target, $css);
        $this->cleaner->cleanupVersionsFor($dir, $name, 'css', $target);

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
            throw new \RuntimeException("Failed to stage versioned CSS file at '{$tmp}'.");
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to commit versioned CSS file to '{$target}'.");
        }
    }

    /**
     * Atomic content write: stage in same-directory temp file, then rename onto target.
     * Same guarantee as atomicCopy() but for in-memory content (createCss output).
     */
    private function atomicWrite(string $target, string $content): void
    {
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to stage generated CSS at '{$tmp}'.");
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to commit generated CSS to '{$target}'.");
        }
    }
}
