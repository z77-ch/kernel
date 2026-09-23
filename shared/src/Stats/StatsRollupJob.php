<?php

namespace Z77\Shared\Stats;

use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Daily rollup of the web statistics — the cron wrapper of {@see StatsRollup}.
 *
 * Registered by `backendConfig` as `stats-rollup` WITH `daily@04:40` (owner
 * decision E1, 2026-09-23 — jobs.md JOBS-SCHED-001): the job deletes the
 * installation's data, which normally means «no defaultSchedule», but here
 * the deletion IS the privacy promise («raw lines are deleted after 7 days»).
 * A promise that waits for an operator to switch it on is not being kept, and
 * raw files with visitor keys piling up for months is the worse risk. The
 * seeded schedule belongs to the operator afterwards (ADR-031 decision 4) —
 * switching it off in Backend → Service → Jobs survives every update.
 *
 * Runs in a single slice: a day file is read line by line, and seven days
 * of a small site are seconds of work. Loud where it matters: a month file
 * that could not be read or saved and a day file that could not be read FAIL
 * the job (their raw files are kept, the next run tries again — a corrupt
 * month file is left for a hand fix and named); a day that hit
 * {@see StatsRollup::MAX_KEYS_PER_DAY} is saved but the note starts with the
 * warning, so the job log shows the flood. The `capped` count in the note is
 * the sum of all caps (page views per visitor and day, beacon events per
 * visitor, day and name, and lines beyond the key ceiling).
 */
final class StatsRollupJob implements Job
{
    public function run(JobContext $context): JobResult
    {
        $result = StatsRollup::fromProject()->run();

        $note = sprintf(
            '%d day(s) folded (%d lines, %d capped by the abuse caps), %d raw file(s) deleted after %d days, %d aggregate(s) deleted after %d months',
            count($result['folded']),
            $result['lines'],
            $result['capped'],
            $result['rawDeleted'],
            StatsRollup::RAW_RETENTION_DAYS,
            $result['aggregatesDeleted'],
            StatsRollup::AGGREGATE_RETENTION_MONTHS
        );

        if ($result['overflow'] !== []) {
            $days = [];
            foreach ($result['overflow'] as $day => $dropped) {
                $days[] = sprintf('%s (%d line(s) dropped)', $day, $dropped);
            }
            $note = sprintf(
                '⚠️ visitor table full (MAX_KEYS_PER_DAY = %d) on %s — a flood, or a site far larger than this statistic is built for; %s',
                StatsRollup::MAX_KEYS_PER_DAY,
                implode(', ', $days),
                $note
            );
        }

        $problems = [];
        if ($result['failed'] !== []) {
            $problems[] = sprintf(
                'aggregate(s) %s under %s could not be read or saved — a corrupt file is left in place for a hand fix, its raw files are kept',
                implode(', ', array_unique($result['failed'])),
                StatsRollup::AGGREGATE_DIR
            );
        }
        if ($result['failedDays'] !== []) {
            $problems[] = sprintf(
                'raw file(s) of %s could not be read — kept, not folded, not marked done',
                implode(', ', $result['failedDays'])
            );
        }
        if ($problems !== []) {
            return JobResult::failed(implode('; ', $problems) . '; ' . $note);
        }

        return JobResult::done($note);
    }
}
