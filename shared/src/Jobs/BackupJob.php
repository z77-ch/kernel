<?php

namespace Z77\Shared\Jobs;

use Z77\Shared\Backup\BackupService;
use Z77\Shared\Backup\BackupType;

/**
 * One backup run as a job (ADR-031), so the scheduled backup goes through the
 * same cron line, the same lock and the same visible history as everything
 * else. `vendor/bin/z77-backup` stays for the manual call — both end up in
 * {@see BackupService}, which is HTTP-free precisely so it can be shared.
 *
 * The type comes from the payload, so ONE class serves all three registry
 * entries (`backup-data`, `backup-db`, `backup-full`) instead of three
 * near-identical subclasses.
 *
 * Runs in a single slice and cannot be cut into more: `ZipArchive` writes one
 * archive in one call, and there is no resume point inside it. A full backup of
 * a large project will therefore overrun the runner's budget — deliberately.
 * The job lock keeps a second copy from starting, `maxParallel` keeps the other
 * jobs moving, and the pass simply ends when the archive is done.
 *
 * `db` without a configured database is a no-op success, not a failure, so one
 * schedule fits every installation.
 */
final class BackupJob implements Job
{
    public function run(JobContext $context): JobResult
    {
        $name = (string) ($context->getPayload()['type'] ?? '');
        $type = BackupType::fromName($name);

        if ($type === null) {
            return JobResult::failed("Unknown backup type '{$name}' — expected data, db or full");
        }

        $service = BackupService::fromProjectRoot(ABS_BASE_PATH);

        if ($type === BackupType::Db && !$service->isDatabaseConfigured()) {
            return JobResult::done('db skipped — no database configured (config/backup.inc.php)');
        }

        // The archive cannot be interrupted, so the PHP limit must not cut it
        // in half and leave a truncated zip behind.
        set_time_limit(0);

        try {
            $entry = $service->run($type, 'cron');
        } catch (\RuntimeException $e) {
            return JobResult::failed($type->value . ' failed — ' . $e->getMessage());
        }

        if (!$context->hasTimeLeft(0)) {
            $context->log('archive outlasted the runner budget — expected for large projects');
        }

        return JobResult::done(sprintf(
            '%s ok — %s (%d files, %.1f MB)',
            $type->value,
            $entry->getFileName(),
            (int) ($entry->getMeta()['files'] ?? 0),
            $entry->getSizeBytes() / 1048576
        ));
    }
}
