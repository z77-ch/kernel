<?php

namespace Z77\Shared\Backup;

/**
 * Database restore adapter — the counterpart of {@see DbDumperInterface}, used
 * by {@see RestoreService}. An implementation reads one complete SQL dump into
 * the configured database or throws \RuntimeException; it never fails silently
 * and never reports a partial success.
 *
 * Deliberately NOT symmetric with the dumper's reach: a dump is a read and
 * runs from cron on every installation, a restore destroys the current state
 * and therefore has one frontend only — the CLI (`bin/z77-restore`). The
 * backend surface stops at taking and downloading archives
 * (docs/topics/backup.md).
 */
interface DbRestorerInterface
{
    /**
     * @param array  $dbConfig     host, port, name, user, password as in
     *                             config/client/database.inc.php, plus `mysql`,
     *                             the client binary
     * @param string $sqlFile      absolute path of the dump to read
     * @param bool   $dropExisting drop every table the database currently has
     *                             before reading, so the result IS the archive
     *                             and not a merge of two states
     */
    public function restore(#[\SensitiveParameter] array $dbConfig, string $sqlFile, bool $dropExisting = true): void;
}
