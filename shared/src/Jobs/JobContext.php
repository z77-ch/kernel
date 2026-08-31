<?php

namespace Z77\Shared\Jobs;

use Z77\Shared\Auth\AuthUser;

/**
 * Everything a job receives from the runner for one slice of work (ADR-031).
 *
 * The deadline is ADVISORY. PHP cannot interrupt a running job without pcntl,
 * which shared hosting does not offer, so {@see hasTimeLeft()} is a question the
 * job has to ask itself — and answer by returning {@see JobResult::again()}. A
 * job that never asks will overrun; the runner logs that but cannot prevent it.
 */
final class JobContext
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @param array      $payload  data handed over when the entry was queued
     * @param array|null $cursor   progress returned by the previous slice
     * @param int        $attempt  1 on the first try, then incremented per failure
     * @param int        $deadline unix timestamp this slice should not run past
     */
    public function __construct(
        private string $jobKey,
        private array $payload,
        private ?array $cursor,
        private int $attempt,
        private int $deadline,
        private AuthUser $actor,
    ) {}

    public function getJobKey(): string
    {
        return $this->jobKey;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    /** Null on the first slice; afterwards whatever the job returned last time. */
    public function getCursor(): ?array
    {
        return $this->cursor;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    /**
     * Whether another chunk of work still fits into this runner pass.
     *
     * @param int $reserve seconds to keep free for writing the result back
     */
    public function hasTimeLeft(int $reserve = 5): bool
    {
        return time() + $reserve < $this->deadline;
    }

    /** Seconds left before the deadline; negative once it has passed. */
    public function getSecondsLeft(): int
    {
        return $this->deadline - time();
    }

    /**
     * The identity this job acts under — for audit fields and for services that
     * evaluate ACLs themselves (DMS). It grants nothing at the CLI boundary:
     * whoever starts the runner can already do anything the process can.
     */
    public function getActor(): AuthUser
    {
        return $this->actor;
    }

    public function log(string $line): void
    {
        $this->log[] = $line;
    }

    /** @return list<string> */
    public function getLog(): array
    {
        return $this->log;
    }
}
