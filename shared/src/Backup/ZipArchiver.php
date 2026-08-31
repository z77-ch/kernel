<?php

namespace Z77\Shared\Backup;

/**
 * Recursive ZIP packing with an exclude list (`ext-zip`). Writes to a `.tmp`
 * name and renames on success, so an aborted run never leaves a file that
 * {@see BackupHistory} would list as a valid backup. Files only — empty
 * directories are not recorded (the installer recreates the project tree).
 *
 * SYMLINKED DIRECTORIES ARE FOLLOWED — the release layout (see
 * docs/01-handbook/release-structure.md) turns `data/`, `config/`, `logs/`
 * and `public/media` into links into `shared/`, and the previous
 * RecursiveDirectoryIterator treated a link as a leaf: `hasChildren()` said
 * no (link view), `isFile()` said no (target view — a directory), and the
 * whole tree silently dropped out of the archive.
 *
 * The walk is therefore an explicit scandir()/is_dir() recursion, NOT the
 * iterator with FOLLOW_SYMLINKS: the decision «may I descend?» is asked
 * PATH-BASED, and path resolution treats a real directory, a symlink and an
 * NTFS junction identically. (The flag does not: it consults the directory
 * entry's type, and a Windows junction reports «unknown» there, so the
 * iterator still refuses to descend — measured, not assumed.) Entries keep
 * the link-side path, so the exclude list matches unchanged.
 *
 * The price of following is paid right next to it: a realpath visited set on
 * directories. It stops a cycle (a link pointing at an ancestor would recurse
 * forever) and packs a tree that is reachable under two names only once.
 * ⚠️ Following and the set are a PAIR — never keep one without the other. A
 * flat installation has no links, the set never hits, behaviour is unchanged.
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

        // Cycle/duplicate guard (see class docblock). Seeded with the root so
        // a link pointing back AT the source is a no-op instead of an endless
        // descent.
        $visited  = [];
        $rootReal = realpath($sourceDir);
        if ($rootReal !== false) {
            $visited[str_replace('\\', '/', $rootReal)] = true;
        }

        try {
            $count = $this->addTree($zip, $sourceDir, '', $excludes, $visited);
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($zipPath . '.tmp');
            throw $e;
        }

        $this->finalize($zip, $zipPath);

        return $count;
    }

    /**
     * Adds one directory level and recurses. Excluded subtrees are pruned at
     * descend time (never walks into vendor/ etc.); a dangling link is
     * neither a directory nor a file and is skipped silently.
     *
     * ⚠️ An unreadable directory is LOUD, not skipped: a backup that quietly
     * leaves things out is the failure mode this class exists to avoid.
     *
     * @param array<string, true> $visited realpath (forward slashes) of every
     *                                     directory already descended into
     */
    private function addTree(\ZipArchive $zip, string $dir, string $relPrefix, array $excludes, array &$visited): int
    {
        $entries = scandir($dir);
        if ($entries === false) {
            throw new \RuntimeException("Backup: cannot read directory '{$dir}'.");
        }

        $count = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $rel = $relPrefix === '' ? $entry : $relPrefix . '/' . $entry;
            foreach ($excludes as $exclude) {
                if ($rel === $exclude || str_starts_with($rel, $exclude . '/')) {
                    continue 2;
                }
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                // realpath answers the same string for every route to a
                // directory — false means a dangling link, which has nothing
                // to pack either.
                $real = realpath($path);
                if ($real === false) {
                    continue;
                }
                $real = str_replace('\\', '/', $real);
                if (isset($visited[$real])) {
                    continue;
                }
                $visited[$real] = true;
                $count += $this->addTree($zip, $path, $rel, $excludes, $visited);
            } elseif (is_file($path)) {
                if (!$zip->addFile($path, $rel)) {
                    throw new \RuntimeException("Failed to add file to backup archive: {$rel}");
                }
                $count++;
            }
        }

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
