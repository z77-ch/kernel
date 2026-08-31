<?php

namespace Z77\Shared\Jobs;

/**
 * What a job hands back after one slice of work (ADR-031).
 *
 * One return type serves two needs that look different but are not:
 *
 *   again(cursor)                  → continue on the next runner pass
 *   again(cursor, notBefore: 600)  → continue in ten minutes (throttling)
 *
 * The cursor is the job's own notion of progress — an offset, a last-seen id,
 * a batch number. The runner stores it verbatim and never interprets it, so a
 * job may change its shape without the runner knowing.
 */
final class JobResult
{
    private function __construct(
        private JobOutcome $outcome,
        private ?array $cursor,
        private int $notBefore,
        private string $note,
    ) {}

    /** The work is complete; the queue entry is finished. */
    public static function done(string $note = ''): self
    {
        return new self(JobOutcome::Done, null, 0, $note);
    }

    /**
     * More to do. The entry returns to the queue with this cursor.
     *
     * @param array|null $cursor    where to resume; null keeps the previous one
     * @param int        $notBefore seconds to wait before the next slice (0 = next run)
     */
    public static function again(?array $cursor = null, int $notBefore = 0, string $note = ''): self
    {
        return new self(JobOutcome::Again, $cursor, max(0, $notBefore), $note);
    }

    /** The work failed. The runner applies the retry policy of the job. */
    public static function failed(string $reason): self
    {
        return new self(JobOutcome::Failed, null, 0, $reason);
    }

    public function getOutcome(): JobOutcome
    {
        return $this->outcome;
    }

    public function getCursor(): ?array
    {
        return $this->cursor;
    }

    /** Seconds to wait before the next slice — only meaningful for `Again`. */
    public function getNotBefore(): int
    {
        return $this->notBefore;
    }

    /** One line for the run log; the failure reason when the outcome is `Failed`. */
    public function getNote(): string
    {
        return $this->note;
    }

    public function isDone(): bool
    {
        return $this->outcome === JobOutcome::Done;
    }

    public function wantsMore(): bool
    {
        return $this->outcome === JobOutcome::Again;
    }

    public function hasFailed(): bool
    {
        return $this->outcome === JobOutcome::Failed;
    }
}
