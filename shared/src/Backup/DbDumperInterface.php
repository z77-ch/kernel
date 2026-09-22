<?php

namespace Z77\Shared\Backup;

/**
 * Database dump adapter for the `db` backup type. Implementations write a
 * complete SQL dump to $targetSqlFile or throw \RuntimeException — never fail
 * silently. v1 ships {@see MysqlDumper}; further engines plug in here without
 * touching {@see BackupService}.
 */
interface DbDumperInterface
{
    /**
     * @param array  $dbConfig      host, port, name, user, password as in
     *                              config/client/database.inc.php (the backup user
     *                              substituted when the backup config names
     *                              one) plus `mysqldump`, the binary
     * @param string $targetSqlFile absolute path the dump is written to
     */
    public function dump(#[\SensitiveParameter] array $dbConfig, string $targetSqlFile): void;
}
