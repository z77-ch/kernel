<?php

namespace Z77\Shared\Backup;

/**
 * MySQL/MariaDB dump via the `mysqldump` binary (shared-hosting friendly, cyon
 * ships it). Credentials go through a short-lived defaults file
 * ({@see MysqlDefaultsFile}) — never on the command line, where they would be
 * visible in the process list. The way back is {@see MysqlRestorer}.
 */
final class MysqlDumper implements DbDumperInterface
{
    public function dump(#[\SensitiveParameter] array $dbConfig, string $targetSqlFile): void
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException(
                'Database backup needs the PHP exec() function, but it is disabled on this host.'
            );
        }

        $name = trim((string)($dbConfig['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException("Database backup: 'name' is missing in config/client/database.inc.php.");
        }

        $binary = trim((string)($dbConfig['mysqldump'] ?? 'mysqldump'));

        $credentialsFile = MysqlDefaultsFile::write($dbConfig);

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

}
