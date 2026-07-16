<?php

namespace Z77\Shared\Backup;

/**
 * MySQL/MariaDB dump via the `mysqldump` binary (shared-hosting friendly, cyon
 * ships it). Credentials go through a short-lived defaults file — never on the
 * command line, where they would be visible in the process list.
 */
final class MysqlDumper implements DbDumperInterface
{
    public function dump(array $dbConfig, string $targetSqlFile): void
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException(
                'Database backup needs the PHP exec() function, but it is disabled on this host.'
            );
        }

        $name = trim((string)($dbConfig['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException("Database backup: config key 'database.name' is missing.");
        }

        $binary = trim((string)($dbConfig['mysqldump'] ?? 'mysqldump'));

        $credentialsFile = $this->writeCredentialsFile($dbConfig);

        try {
            $cmd = escapeshellarg($binary)
                 . ' --defaults-extra-file=' . escapeshellarg($credentialsFile)
                 . ' --single-transaction --no-tablespaces --result-file=' . escapeshellarg($targetSqlFile)
                 . ' ' . escapeshellarg($name)
                 . ' 2>&1';

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                @unlink($targetSqlFile);
                throw new \RuntimeException(
                    "mysqldump failed (exit {$exitCode}): " . trim(implode(' ', array_slice($output, 0, 3)))
                );
            }
            if (!is_file($targetSqlFile) || filesize($targetSqlFile) === 0) {
                @unlink($targetSqlFile);
                throw new \RuntimeException('mysqldump produced no output file.');
            }
        } finally {
            @unlink($credentialsFile);
        }
    }

    /** Writes host/user/password as a mysql defaults file (0600) and returns its path. */
    private function writeCredentialsFile(array $dbConfig): string
    {
        $lines = ["[client]"];
        $lines[] = 'host=' . (string)($dbConfig['host'] ?? 'localhost');
        if (($dbConfig['port'] ?? null) !== null) {
            $lines[] = 'port=' . (int)$dbConfig['port'];
        }
        $lines[] = 'user=' . (string)($dbConfig['user'] ?? '');
        $lines[] = 'password="' . str_replace('"', '\"', (string)($dbConfig['pass'] ?? '')) . '"';

        $file = tempnam(sys_get_temp_dir(), 'z77db');
        if ($file === false
            || file_put_contents($file, implode("\n", $lines) . "\n") === false
        ) {
            throw new \RuntimeException('Database backup: failed to write the temporary credentials file.');
        }
        @chmod($file, 0600);

        return $file;
    }
}
