<?php

namespace Z77\Shared\Jobs;

/**
 * How one slice of work ended. `Again` is the interesting one: it covers both
 * throttling (come back in ten minutes) and slicing (come back next run) —
 * the difference is only the delay carried by {@see JobResult} (ADR-031).
 */
enum JobOutcome: string
{
    case Done   = 'done';
    case Again  = 'again';
    case Failed = 'failed';
}
