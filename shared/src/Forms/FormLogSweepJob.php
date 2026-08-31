<?php

namespace Z77\Shared\Forms;

use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Deletes form-log months past their retention ({@see FormLog::RETENTION_DAYS})
 * — the log's own broom, beside the log it sweeps.
 *
 * Registered by `backendConfig` as `form-log-cleanup`, and deliberately
 * WITHOUT a `defaultSchedule`: the job deletes the installation's data, so an
 * operator switches it on (the delete-vs-schedule rule, ADR-031 — same stance
 * as `member-cleanup`, opposite of `geoip-update`, whose run is a licence
 * obligation).
 *
 * Runs in a single slice: the work is one glob over monthly files.
 */
final class FormLogSweepJob implements Job
{
    public function run(JobContext $context): JobResult
    {
        $removed = FormLog::sweep();

        return JobResult::done(sprintf(
            '%d expired form-log file(s) deleted (retention %d days)',
            $removed,
            FormLog::RETENTION_DAYS
        ));
    }
}
