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
     * @param array  $dbConfig      the `database` block from config/backup.inc.php
     * @param string $targetSqlFile absolute path the dump is written to
     */
    public function dump(array $dbConfig, string $targetSqlFile): void;
}
