<?php

namespace Z77\Shared\Backup;

/**
 * A short-lived mysql defaults file (0600) carrying host, port, user and
 * password — the one way credentials reach `mysqldump` / `mysql` without
 * standing in the process list, where any user of the machine reads them.
 *
 * Shared by {@see MysqlDumper} and {@see MysqlRestorer}: the pair must agree
 * on the file's shape, and a second hand-written copy would be the place where
 * they stop agreeing.
 */
final class MysqlDefaultsFile
{
    /** Writes the file and returns its path; the caller deletes it in a `finally`. */
    public static function write(#[\SensitiveParameter] array $dbConfig): string
    {
        $lines = ['[client]'];
        $lines[] = 'host=' . (string)($dbConfig['host'] ?? 'localhost');
        if (($dbConfig['port'] ?? null) !== null) {
            $lines[] = 'port=' . (int)$dbConfig['port'];
        }
        $lines[] = 'user=' . (string)($dbConfig['user'] ?? '');
        $lines[] = 'password="' . str_replace('"', '\"', (string)($dbConfig['password'] ?? '')) . '"';

        $file = tempnam(sys_get_temp_dir(), 'z77db');
        if ($file === false || file_put_contents($file, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Database access: failed to write the temporary credentials file.');
        }
        @chmod($file, 0600);

        return $file;
    }
}
