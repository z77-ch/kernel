<?php

namespace Z77\Shared\Backup;

/**
 * Recursive ZIP packing with an exclude list (`ext-zip`). Writes to a `.tmp`
 * name and renames on success, so an aborted run never leaves a file that
 * {@see BackupHistory} would list as a valid backup. Files only — empty
 * directories are not recorded (the installer recreates the project tree).
 */
final class ZipArchiver
{
    /**
     * Packs $sourceDir recursively into $zipPath.
     *
     * @param list<string> $excludeRelPaths paths relative to $sourceDir (forward
     *                                      slashes, no leading slash); an entry
     *                                      excludes that file or whole subtree.
     *
     * @return int number of files added
     */
    public function zipDirectory(string $sourceDir, string $zipPath, array $excludeRelPaths = []): int
    {
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        if (!is_dir($sourceDir)) {
            throw new \RuntimeException("Backup source directory not found: {$sourceDir}");
        }

        $excludes = array_values(array_filter(array_map(
            static fn(string $p): string => trim(str_replace('\\', '/', $p), '/'),
            $excludeRelPaths
        ), static fn(string $p): bool => $p !== ''));

        $zip = $this->openForWrite($zipPath . '.tmp');

        // Prune excluded subtrees at descend time (never walks into vendor/ etc.).
        $inner  = new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $inner,
            function (\SplFileInfo $item) use ($sourceDir, $excludes): bool {
                $rel = $this->relativePath($sourceDir, $item->getPathname());
                foreach ($excludes as $exclude) {
                    if ($rel === $exclude || str_starts_with($rel, $exclude . '/')) {
                        return false;
                    }
                }
                return true;
            }
        );

        $count = 0;
        foreach (new \RecursiveIteratorIterator($filter) as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $rel = $this->relativePath($sourceDir, $item->getPathname());
            if (!$zip->addFile($item->getPathname(), $rel)) {
                $zip->close();
                @unlink($zipPath . '.tmp');
                throw new \RuntimeException("Failed to add file to backup archive: {$rel}");
            }
            $count++;
        }

        $this->finalize($zip, $zipPath);

        return $count;
    }

    /** Packs a single file into $zipPath under $entryName (used for DB dumps). */
    public function zipFile(string $filePath, string $zipPath, string $entryName): int
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Backup source file not found: {$filePath}");
        }

        $zip = $this->openForWrite($zipPath . '.tmp');
        if (!$zip->addFile($filePath, $entryName)) {
            $zip->close();
            @unlink($zipPath . '.tmp');
            throw new \RuntimeException("Failed to add file to backup archive: {$entryName}");
        }
        $this->finalize($zip, $zipPath);

        return 1;
    }

    private function openForWrite(string $tmpPath): \ZipArchive
    {
        $zip    = new \ZipArchive();
        $result = $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new \RuntimeException("Failed to create backup archive (ZipArchive error {$result}): {$tmpPath}");
        }
        return $zip;
    }

    private function finalize(\ZipArchive $zip, string $zipPath): void
    {
        if (!$zip->close()) {
            @unlink($zipPath . '.tmp');
            throw new \RuntimeException("Failed to write backup archive: {$zipPath}");
        }
        if (!rename($zipPath . '.tmp', $zipPath)) {
            @unlink($zipPath . '.tmp');
            throw new \RuntimeException("Failed to move backup archive into place: {$zipPath}");
        }
    }

    private function relativePath(string $baseDir, string $path): string
    {
        return ltrim(substr(str_replace('\\', '/', $path), strlen($baseDir)), '/');
    }
}
