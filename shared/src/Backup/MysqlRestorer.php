<?php

namespace Z77\Shared\Backup;

/**
 * MySQL/MariaDB restore via the `mysql` client binary — the counterpart of
 * {@see MysqlDumper}, and like it credential-safe through
 * {@see MysqlDefaultsFile} (never the command line, never the process list).
 *
 * Two steps, both loud on failure:
 *
 *  1. optional `DROP TABLE` of every table the database currently holds, with
 *     `FOREIGN_KEY_CHECKS=0` so the order of the drops does not matter — a
 *     `mysqldump` file carries `CREATE TABLE` for what it knows and says
 *     nothing about a table that exists only here, which would otherwise
 *     survive and make the result a merge of two states;
 *  2. the dump itself, read on stdin.
 *
 * The binary stays a bare name resolved via PATH for the same reason
 * `mysqldump` does — the config it would be written into is synced across
 * machines, the binary's location is not (backup.md BACKUP-DUMP-PATH-001).
 */
final class MysqlRestorer implements DbRestorerInterface
{
    public function restore(#[\SensitiveParameter] array $dbConfig, string $sqlFile, bool $dropExisting = true): void
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException(
                'Database restore needs the PHP exec() function, but it is disabled on this host.'
            );
        }
        if (!is_file($sqlFile) || filesize($sqlFile) === 0) {
            throw new \RuntimeException("Database restore: '{$sqlFile}' is missing or empty.");
        }

        $name = trim((string)($dbConfig['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException("Database restore: 'name' is missing in config/client/database.inc.php.");
        }

        $binary          = trim((string)($dbConfig['mysql'] ?? '')) !== '' ? (string)$dbConfig['mysql'] : 'mysql';
        $credentialsFile = MysqlDefaultsFile::write($dbConfig);

        try {
            if ($dropExisting) {
                $this->dropAllTables($binary, $credentialsFile, $name);
            }

            $this->run(
                $binary,
                $credentialsFile,
                $name,
                ' < ' . escapeshellarg($sqlFile),
                'reading the dump'
            );
        } finally {
            @unlink($credentialsFile);
        }
    }

    private function dropAllTables(string $binary, string $credentialsFile, string $name): void
    {
        $tables = $this->run(
            $binary,
            $credentialsFile,
            $name,
            ' --batch --skip-column-names --execute=' . escapeshellarg('SHOW TABLES'),
            'listing the existing tables'
        );
        $tables = array_values(array_filter(array_map('trim', $tables), static fn(string $t): bool => $t !== ''));
        if ($tables === []) {
            return;
        }

        // The statement is built from names the SERVER just reported, not from
        // input; the backtick quoting is for names needing it (`order`), not a
        // trust boundary.
        $sql = "SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS "
             . implode(', ', array_map(static fn(string $t): string => '`' . str_replace('`', '``', $t) . '`', $tables))
             . ";\nSET FOREIGN_KEY_CHECKS=1;\n";

        $file = tempnam(sys_get_temp_dir(), 'z77drop');
        if ($file === false || file_put_contents($file, $sql) === false) {
            throw new \RuntimeException('Database restore: failed to write the temporary drop script.');
        }

        try {
            $this->run($binary, $credentialsFile, $name, ' < ' . escapeshellarg($file), 'dropping the existing tables');
        } finally {
            @unlink($file);
        }
    }

    /**
     * One `mysql` invocation. $tail is appended AFTER the database name, so a
     * stdin redirect lands where the shell expects it.
     *
     * @return list<string> the command's output lines
     */
    private function run(string $binary, string $credentialsFile, string $name, string $tail, string $step): array
    {
        $cmd = escapeshellarg($binary)
             . ' --defaults-extra-file=' . escapeshellarg($credentialsFile)
             . ' ' . escapeshellarg($name)
             . $tail
             . ' 2>&1';

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "mysql failed while {$step} (exit {$exitCode}): "
                . trim(implode(' ', array_slice($output, 0, 3)))
            );
        }

        return $output;
    }
}
