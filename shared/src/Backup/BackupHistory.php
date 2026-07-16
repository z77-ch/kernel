<?php

namespace Z77\Shared\Backup;

/**
 * Backup history via directory scan — the file system is the single source of
 * truth (ADR-025 philosophy, no central history file that could drift). Reads
 * `{backupRoot}/{type}/*.zip` plus the optional `*.meta.json` sidecars.
 */
final class BackupHistory
{
    /** Archive file-name contract; also the traversal guard for download/delete. */
    public const FILE_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}_(data|db|full)\.zip$/';

    public function __construct(private string $backupRoot) {}

    /**
     * All backups of one type, newest first.
     *
     * @return list<BackupEntry>
     */
    public function scan(BackupType $type): array
    {
        $dir = $this->typeDir($type);
        if (!is_dir($dir)) {
            return [];
        }

        $entries = [];
        foreach (scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $file) {
            if (!preg_match(self::FILE_PATTERN, $file)) {
                continue;
            }
            $path = $dir . '/' . $file;

            $meta     = [];
            $metaPath = $this->metaPath($path);
            if (is_file($metaPath)) {
                $decoded = json_decode((string)file_get_contents($metaPath), true);
                $meta    = is_array($decoded) ? $decoded : [];
            }

            $entries[] = new BackupEntry(
                type:      $type,
                fileName:  $file,
                createdAt: (new \DateTimeImmutable())->setTimestamp((int)filemtime($path)),
                sizeBytes: (int)filesize($path),
                meta:      $meta,
            );
        }

        // File names sort chronologically (date-first), scandir already returned
        // them descending — newest first without a second sort pass.
        return $entries;
    }

    /**
     * Resolves a submitted archive file name to its absolute path — only names
     * matching the archive contract are accepted (no traversal, no foreign
     * files), and the type token in the name must match the requested type.
     * Returns null when the file does not exist.
     */
    public function resolvePath(BackupType $type, string $fileName): ?string
    {
        if (!preg_match(self::FILE_PATTERN, $fileName, $m) || $m[1] !== $type->value) {
            return null;
        }
        $path = $this->typeDir($type) . '/' . $fileName;

        return is_file($path) ? $path : null;
    }

    public function typeDir(BackupType $type): string
    {
        return rtrim(str_replace('\\', '/', $this->backupRoot), '/') . '/' . $type->value;
    }

    /** Sidecar path for an archive: `…_data.zip` → `…_data.meta.json`. */
    public function metaPath(string $zipPath): string
    {
        return substr($zipPath, 0, -strlen('.zip')) . '.meta.json';
    }
}
