<?php

namespace Z77\Shared\Backup;

use Z77\Shared\Libraries\ConfigLocator;

/**
 * Reads one SQL dump back into the installation's database — the way back from
 * {@see BackupService}, and deliberately NOT its mirror image.
 *
 * **Asymmetric on purpose.** Taking a backup is a read: it runs from cron on
 * every installation and has two frontends (backend screen + CLI). A restore
 * destroys the current state, so it has ONE frontend, the CLI
 * (`bin/z77-restore`), and the backend keeps no button for it. Creating and
 * downloading an archive is an operator's job; replacing a live database is a
 * deliberate act at a shell, with the project root in front of you.
 *
 * Three safeguards, none of them optional by accident:
 *
 *  - **The target is never an argument.** Host, database and credentials come
 *    from `config/client/database.inc.php` (ADR-039), the same single
 *    connection config the Doctrine driver and the dump read. A `--host` /
 *    `--database` switch is what turns a dev tool into the one that reaches
 *    production.
 *  - **A safety dump runs first** ({@see BackupService}, type `db`), and a
 *    failure there aborts the restore — the state about to be overwritten must
 *    exist as an archive before it is gone.
 *  - **The existing tables are dropped** unless told otherwise, so the result
 *    IS the archive instead of a merge of two schemas.
 *
 * The source may be an archive of this installation (resolved through
 * {@see BackupHistory}, pattern + type checked — never a concatenated path) or
 * a `.zip` / `.sql` file anywhere on the machine: a dump from another computer
 * arrives as a file, which is the case this exists for.
 */
final class RestoreService
{
    private string $baseDir;
    private array  $config;
    private array  $database;

    /**
     * @param array $config   the backup policy (`config/client/backup.inc.php`) — only `dump` matters here
     * @param array $database the connection (`config/client/database.inc.php`)
     */
    public function __construct(
        string $baseDir,
        array $config = [],
        #[\SensitiveParameter] array $database = [],
        private ?DbRestorerInterface $restorer = null,
        private ?BackupService $backup = null
    ) {
        $this->baseDir  = rtrim(str_replace('\\', '/', $baseDir), '/');
        $this->config   = $config;
        $this->database = $database;
    }

    public static function fromProjectRoot(string $baseDir): self
    {
        return new self(
            $baseDir,
            self::readConfig($baseDir, 'backup'),
            self::readConfig($baseDir, 'database'),
            null,
            BackupService::fromProjectRoot($baseDir)
        );
    }

    /** ADR-036 split lookup with the root passed explicitly (as {@see BackupService} does). */
    private static function readConfig(string $baseDir, string $name): array
    {
        $file = ConfigLocator::path("{$name}.inc.php", $baseDir);
        if ($file === null) {
            return [];
        }
        $config = require $file;

        return is_array($config) ? $config : [];
    }

    public function isDatabaseConfigured(): bool
    {
        return $this->databaseName() !== '';
    }

    public function databaseName(): string
    {
        return trim((string)($this->database['name'] ?? ''));
    }

    /**
     * The archives of this installation that may carry a dump, newest first —
     * what the CLI lists when it is called without a source.
     *
     * @return list<array{type: string, file: string, size: int, time: int}>
     */
    public function sources(): array
    {
        $history = $this->backupService()->history();
        $rows    = [];

        foreach ([BackupType::Db, BackupType::Full] as $type) {
            foreach ($history->scan($type) as $entry) {
                $rows[] = [
                    'type' => $type->value,
                    'file' => $entry->getFileName(),
                    'size' => $entry->getSizeBytes(),
                    'time' => $entry->getCreatedAt()->getTimestamp(),
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);

        return $rows;
    }

    /**
     * Turns what the caller typed into an existing file: an archive NAME is
     * resolved inside the backup root through {@see BackupHistory} (its pattern
     * and type check are the reason a name never becomes a concatenated path),
     * anything else is taken as a path to a `.zip` or `.sql` file.
     */
    public function resolveSource(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            throw new \RuntimeException('Restore: no source given.');
        }

        if (preg_match(BackupHistory::FILE_PATTERN, $source, $match) === 1) {
            $type = BackupType::fromName($match[1]);
            $path = $type !== null
                ? $this->backupService()->history()->resolvePath($type, $source)
                : null;
            if ($path === null) {
                throw new \RuntimeException("Restore: archive '{$source}' not found in the backup root.");
            }

            return $path;
        }

        $path = str_replace('\\', '/', $source);
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Restore: '{$source}' is neither an archive of this installation nor an existing file."
            );
        }
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['zip', 'sql'], true)) {
            throw new \RuntimeException("Restore: '{$source}' is neither a .zip archive nor a .sql dump.");
        }

        return $path;
    }

    /**
     * Restores $source into the configured database.
     *
     * @param  bool $dropExisting drop the current tables first (default) — off makes the result a merge
     * @param  bool $safetyBackup take a `db` backup of the state about to be replaced (default)
     * @return array{source: string, sql: string, bytes: int, safety: ?string}
     * @throws \RuntimeException on anything: no database, unreadable archive, no dump inside, a failing step
     */
    public function restore(string $source, bool $dropExisting = true, bool $safetyBackup = true): array
    {
        if (!$this->isDatabaseConfigured()) {
            throw new \RuntimeException(
                'No database configured — set "name" (and the credentials) in config/client/database.inc.php first.'
            );
        }

        $path = $this->resolveSource($source);

        [$sqlFile, $sqlLabel, $temporary] = $this->sqlFrom($path);

        try {
            // Before anything is dropped: the state about to disappear must be
            // an archive. A failure here is fatal by design — a restore whose
            // predecessor could not be saved is the one nobody can undo.
            $safety = null;
            if ($safetyBackup) {
                $safety = $this->backupService()->run(BackupType::Db, 'restore')->getFileName();
            }

            ($this->restorer ?? new MysqlRestorer())->restore($this->restoreConfig(), $sqlFile, $dropExisting);

            return [
                'source' => $path,
                'sql'    => $sqlLabel,
                'bytes'  => (int)filesize($sqlFile),
                'safety' => $safety,
            ];
        } finally {
            if ($temporary) {
                @unlink($sqlFile);
            }
        }
    }

    private function backupService(): BackupService
    {
        return $this->backup ??= BackupService::fromProjectRoot($this->baseDir);
    }

    /**
     * The dump to read: a `.sql` file is itself, a `.zip` is searched for one.
     * Preferred is `database/{name}.sql` (what a `full` archive carries since
     * 2026-10-09); otherwise the single `.sql` entry (a `db` archive holds
     * exactly one, named after the archive). Several candidates with none
     * preferred is a choice the tool must not make for the operator.
     *
     * @return array{0: string, 1: string, 2: bool} file, label, is-temporary
     */
    private function sqlFrom(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql') {
            return [$path, basename($path), false];
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Restore: cannot open the archive '{$path}'.");
        }

        try {
            $preferred = 'database/' . $this->databaseName() . '.sql';
            $entry     = $zip->locateName($preferred) !== false ? $preferred : null;

            if ($entry === null) {
                $candidates = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string)$zip->getNameIndex($i);
                    if (str_ends_with(strtolower($name), '.sql')) {
                        $candidates[] = $name;
                    }
                }
                if ($candidates === []) {
                    throw new \RuntimeException(
                        'Restore: the archive carries no .sql dump — a `data` archive never does, and a `full` one '
                        . 'only when a database was configured when it was taken.'
                    );
                }
                if (count($candidates) > 1) {
                    throw new \RuntimeException(
                        'Restore: the archive carries several .sql files ('
                        . implode(', ', array_slice($candidates, 0, 5))
                        . ') and none is ' . $preferred . ' — extract the one you mean and pass its path.'
                    );
                }
                $entry = $candidates[0];
            }

            $stream = $zip->getStream($entry);
            if ($stream === false) {
                throw new \RuntimeException("Restore: cannot read '{$entry}' from the archive.");
            }

            $target = tempnam(sys_get_temp_dir(), 'z77sql');
            if ($target === false) {
                fclose($stream);
                throw new \RuntimeException('Restore: failed to create a temporary file for the dump.');
            }

            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                @unlink($target);
                throw new \RuntimeException('Restore: failed to write the temporary dump file.');
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            if (filesize($target) === 0) {
                @unlink($target);
                throw new \RuntimeException("Restore: '{$entry}' in the archive is empty.");
            }

            return [$target, $entry, true];
        } finally {
            $zip->close();
        }
    }

    /**
     * The connection as the restorer sees it: the application user from
     * `database.inc.php` plus the `mysql` client binary from the backup
     * config's `dump` block. The read-only backup user is deliberately NOT
     * substituted here — it may read and must not write.
     */
    private function restoreConfig(): array
    {
        $dump = is_array($this->config['dump'] ?? null) ? $this->config['dump'] : [];

        $dbConfig          = $this->database;
        $dbConfig['mysql'] = $dump['mysql'] ?? 'mysql';

        return $dbConfig;
    }
}
