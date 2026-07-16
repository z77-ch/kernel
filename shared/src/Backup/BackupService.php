<?php

namespace Z77\Shared\Backup;

/**
 * Installation-wide backup orchestration — deliberately HTTP-free so the
 * backend UI and the CLI entry (`vendor/bin/z77-backup`, ADR-028) share one
 * implementation. Reads its settings from `config/backup.inc.php` (seed-once,
 * see docs/topics/backup.md); every failure throws \RuntimeException.
 *
 * Types: data (the data/ tree), db (SQL dump, only when a database is
 * configured), full (project root minus the configured excludes).
 */
final class BackupService
{
    private const DEFAULT_DIR       = 'backup';
    private const DEFAULT_RETENTION = ['data' => 10, 'db' => 10, 'full' => 5];
    private const DEFAULT_EXCLUDES  = ['vendor', 'node_modules', 'backup', 'lib/cache'];

    private string $baseDir;
    private array  $config;

    public function __construct(string $baseDir, array $config = [], private ?DbDumperInterface $dbDumper = null)
    {
        $this->baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        $this->config  = $config;
    }

    /** Builds the service from a project root, reading config/backup.inc.php when present. */
    public static function fromProjectRoot(string $baseDir): self
    {
        $configFile = rtrim(str_replace('\\', '/', $baseDir), '/') . '/config/backup.inc.php';
        $config     = is_file($configFile) ? require $configFile : [];

        return new self($baseDir, is_array($config) ? $config : []);
    }

    public function isDatabaseConfigured(): bool
    {
        return is_array($this->config['database'] ?? null) && $this->config['database'] !== [];
    }

    public function history(): BackupHistory
    {
        return new BackupHistory($this->backupRoot());
    }

    /**
     * Runs one backup and applies the retention policy of its type.
     *
     * @param string $trigger 'manual' (backend UI) or 'cron' (CLI)
     */
    public function run(BackupType $type, string $trigger): BackupEntry
    {
        $startedAt = microtime(true);
        $dir       = $this->history()->typeDir($type);
        $this->ensureDir($dir);

        $zipPath = $dir . '/' . date('Y-m-d_His') . '_' . $type->value . '.zip';

        $files = match ($type) {
            BackupType::Data => (new ZipArchiver())->zipDirectory($this->baseDir . '/data', $zipPath),
            BackupType::Full => (new ZipArchiver())->zipDirectory($this->baseDir, $zipPath, $this->fullExcludes()),
            BackupType::Db   => $this->runDbBackup($zipPath),
        };

        $this->writeMeta($zipPath, [
            'trigger'          => $trigger,
            'started_at'       => date('c', (int)$startedAt),
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            'status'           => 'ok',
            'files'            => $files,
        ]);

        $this->applyRetention($type);

        $scan = $this->history()->scan($type);
        foreach ($scan as $entry) {
            if ($entry->getFileName() === basename($zipPath)) {
                return $entry;
            }
        }
        // Unreachable unless retention 0-length-deleted the fresh archive.
        throw new \RuntimeException('Backup archive vanished after creation: ' . basename($zipPath));
    }

    /** Deletes one backup (archive + meta sidecar). Unknown names are rejected upstream. */
    public function delete(BackupType $type, string $fileName): void
    {
        $path = $this->history()->resolvePath($type, $fileName);
        if ($path === null) {
            throw new \RuntimeException("Backup not found: {$fileName}");
        }
        if (!unlink($path)) {
            throw new \RuntimeException("Failed to delete backup: {$fileName}");
        }
        $meta = $this->history()->metaPath($path);
        if (is_file($meta) && !unlink($meta)) {
            throw new \RuntimeException("Failed to delete backup meta file: " . basename($meta));
        }
    }

    public function backupRoot(): string
    {
        $dir = trim((string)($this->config['dir'] ?? self::DEFAULT_DIR), '/');

        return $this->baseDir . '/' . ($dir === '' ? self::DEFAULT_DIR : $dir);
    }

    /** @return list<string> project-relative exclude paths for the full backup */
    public function fullExcludes(): array
    {
        $configured = $this->config['fullExcludes'] ?? self::DEFAULT_EXCLUDES;
        $excludes   = is_array($configured) ? array_values($configured) : self::DEFAULT_EXCLUDES;

        // The backup root itself is always excluded — a full backup that
        // contains all previous backups grows without bound (recursion guard).
        $backupRel = ltrim(substr($this->backupRoot(), strlen($this->baseDir)), '/');
        if (!in_array($backupRel, $excludes, true)) {
            $excludes[] = $backupRel;
        }

        return $excludes;
    }

    private function runDbBackup(string $zipPath): int
    {
        if (!$this->isDatabaseConfigured()) {
            throw new \RuntimeException(
                'No database configured — set the "database" block in config/backup.inc.php first.'
            );
        }

        $dumper  = $this->dbDumper ?? new MysqlDumper();
        $sqlFile = $zipPath . '.sql';

        try {
            $dumper->dump((array)$this->config['database'], $sqlFile);
            return (new ZipArchiver())->zipFile($sqlFile, $zipPath, basename($zipPath, '.zip') . '.sql');
        } finally {
            @unlink($sqlFile);
        }
    }

    /** Keeps the newest N archives per type (config `retention`, 0 = unlimited). */
    private function applyRetention(BackupType $type): void
    {
        $retention = $this->config['retention'][$type->value]
            ?? self::DEFAULT_RETENTION[$type->value];
        $keep = max(0, (int)$retention);
        if ($keep === 0) {
            return;
        }

        $entries = $this->history()->scan($type); // newest first
        foreach (array_slice($entries, $keep) as $entry) {
            $this->delete($type, $entry->getFileName());
        }
    }

    private function writeMeta(string $zipPath, array $meta): void
    {
        $metaPath = $this->history()->metaPath($zipPath);
        $json     = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($metaPath, $json) === false) {
            throw new \RuntimeException('Failed to write backup meta file: ' . basename($metaPath));
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create backup directory: {$dir}");
        }
    }
}
