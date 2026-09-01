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

    /**
     * `var` — the whole tree, not `var/cache`. Everything below `var/` is
     * scratch space the installation rebuilds by itself: the page cache, the
     * APCu stamp, the release switches, the throttle counters. It may be deleted
     * at any moment without losing information, which is exactly what an archive
     * has no reason to carry. (`var` was `lib` until ADR-035 renamed it; an
     * installation whose seed-once config still says `lib` keeps excluding a
     * directory that no longer exists and silently archives `var/` — the
     * migration step that fixes that is in release-structure.md.)
     *
     * Naming the tree instead of listing its members is the point: a future
     * `var/something` is covered the day it appears. The list would need
     * maintaining, and the one time it was not, the throttle counters ended up
     * in every full archive.
     *
     * `logs/` is deliberately NOT under `var/` and NOT excluded: it carries the
     * form log (FormLog — submitted enquiries), which is a record and belongs in
     * the archive.
     */
    private const DEFAULT_EXCLUDES  = ['vendor', 'node_modules', 'backup', 'var'];

    /**
     * Never part of a data backup, relative to `data/`: the job runtime state
     * (queue, schedules' bookkeeping, lock files, heartbeat).
     *
     * Two reasons, both binding. It is transient state — a restore must not
     * resurrect a queue of work from whenever the archive was taken, the same
     * argument that keeps a running job out of systemConfig (bootstrap.md).
     * And it MOVES while the archive is being written: `ZipArchive` reads the
     * files at `close()`, not at `addFile()`, so a `queue.json` replaced by the
     * very backup job that is running fails the whole archive with
     * "ZipArchive::close(): Read error".
     *
     * Not configurable — this is not an operator's choice.
     *
     * `framework/import` (ADR-032) is the same class of state: the staging
     * area + persisted import plans are transient — restoring them into
     * another environment would resurrect a half-decided import against data
     * that no longer matches its fingerprints.
     */
    private const DATA_EXCLUDES = ['framework/jobs', 'framework/import'];

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
            BackupType::Data => (new ZipArchiver())->zipDirectory($this->baseDir . '/data', $zipPath, self::DATA_EXCLUDES),
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

        // The job runtime state is excluded here too, and unconditionally: the
        // configured list is the operator's, but this one is not negotiable —
        // see DATA_EXCLUDES for why (transient state + it moves mid-archive).
        foreach (self::DATA_EXCLUDES as $jobPath) {
            $rel = 'data/' . $jobPath;
            if (!in_array($rel, $excludes, true)) {
                $excludes[] = $rel;
            }
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

    /**
     * Applies the type's retention (config `retention`): an integer keeps the
     * newest N (0 = unlimited), an array is the tiered form — all of the last
     * days, one per week, one per month … so a mistake discovered LATE still
     * has a clean state to restore. The decision itself is
     * {@see RetentionPolicy} (pure, harness-tested); this method only feeds
     * it the names and deletes what it drops.
     */
    private function applyRetention(BackupType $type): void
    {
        $retention = $this->config['retention'][$type->value]
            ?? self::DEFAULT_RETENTION[$type->value];

        $names = array_map(
            static fn(BackupEntry $entry): string => $entry->getFileName(),
            $this->history()->scan($type),
        );

        foreach (RetentionPolicy::drops($names, is_array($retention) ? $retention : (int) $retention) as $name) {
            $this->delete($type, $name);
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
