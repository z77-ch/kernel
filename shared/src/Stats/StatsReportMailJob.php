<?php

namespace Z77\Shared\Stats;

use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * The monthly statistics mail — step 2 of docs/03-development/web-stats-bauplan.md,
 * see docs/topics/stats.md. The cron wrapper of {@see StatsReportMailer}.
 *
 * Registered by `backendConfig` as `stats-report-mail` WITHOUT a default
 * schedule: a job that writes outward is switched on by a human (the rule of
 * `form-log-cleanup`, ADR-031). Suggested `monthly@1,06:00` — AFTER the
 * rollup of 04:40 has folded the month's last day; a time before 04:40 sends
 * the month without its last day.
 *
 * Which month: the payload's `month` (`YYYY-MM`) when given, otherwise the
 * month before the run — which is what the schedule wants on the 1st, and
 * what «Jetzt einreihen» on the job screen gets mid-month too. A hand send of
 * a chosen month is the backend report page's «jetzt senden», which calls the
 * mailer directly and does not go through here.
 *
 * Fails loudly: no key, no `canonicalBaseUrl`, a refused send → the job
 * fails with the reason. A month without an aggregate is `done` with a note
 * and no mail.
 */
final class StatsReportMailJob implements Job
{
    public function run(JobContext $context): JobResult
    {
        $payload = $context->getPayload()['month'] ?? null;
        $month   = $payload === null || $payload === ''
            ? StatsReport::previousMonth(date('Y-m'))
            : (is_string($payload) ? $payload : '');

        $result = (new StatsReportMailer())->send($month);

        return $result['status'] === 'failed'
            ? JobResult::failed($result['note'])
            : JobResult::done($result['note']);
    }
}
