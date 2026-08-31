<?php

namespace Z77\Shared\Jobs;

/**
 * One unit of background work, registered under a key in a module config
 * (`jobs` map) and executed by {@see JobRunner} (ADR-031).
 *
 * An implementation MUST be constructible without HTTP context — no Request,
 * no session, no controller. It MUST also tolerate being called again with the
 * cursor it returned last time: a job that cannot resume can only ever run in
 * one slice, which the runner's time budget does not guarantee.
 */
interface Job
{
    public function run(JobContext $context): JobResult;
}
