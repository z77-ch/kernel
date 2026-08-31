<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One piece of queued work (ADR-031). Enqueued by the application, by the
 * backend, or by a due schedule — and always executed by the runner, never by
 * whoever enqueued it. That single execution path is the point: retry, locking
 * and logging exist once.
 *
 * `state` is display and crash detection, NOT the guard against a double start.
 * That is the job lock, which the operating system releases when its process
 * dies; a flag written into JSON would survive the crash and block the job
 * forever. An entry left in 'running' with no lock held is therefore an orphan
 * from a dead run and goes back to 'queued'.
 *
 * `payload` and `cursor` carry no #[Clean] filter deliberately: their shape
 * belongs to the job, not to the framework, and the registry has no filter for
 * a free-form map. Neither is ever rendered as HTML by the runner — a job that
 * puts user input into its payload must clean it where it reads it.
 */
#[Entity('file', 'framework/jobs/queue.json')]
class JobRun
{
    use ArrayMappable;

    public const STATE_QUEUED  = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_DONE    = 'done';
    public const STATE_FAILED  = 'failed';

    /** Server-controlled — no setter; the store assigns it. */
    private ?string $id = null;

    /** Registry key from a module's `jobs` config, e.g. 'member-cleanup'. */
    #[Clean('ident')]
    private string $jobKey = '';

    /** Handed over at enqueue time; the job decides what it means. */
    private array $payload = [];

    /** Progress from the previous slice; null before the first run. */
    private ?array $cursor = null;

    #[Clean('ident')]
    private string $state = self::STATE_QUEUED;

    /** Not picked up before this moment — the throttle in {@see \Z77\Shared\Jobs\JobResult::again()}. */
    #[Clean('text')]
    private string $availableAt = '';

    #[Clean('nullable', 'text')]
    private ?string $startedAt = null;

    #[Clean('nullable', 'text')]
    private ?string $finishedAt = null;

    #[Clean('int')]
    private int $attempts = 0;

    /** Last line from the job — the failure reason once state is 'failed'. */
    #[Clean('text')]
    private string $note = '';

    /** Who enqueued it: an actor name, or 'schedule' / 'cron'. */
    #[Clean('text')]
    private string $createdBy = '';

    #[Clean('text')]
    private string $createdAt = '';

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?string { return $this->id; }
    public function getJobKey(): string { return $this->jobKey; }
    public function getPayload(): array { return $this->payload; }
    public function getCursor(): ?array { return $this->cursor; }
    public function getState(): string { return $this->state; }
    public function getAvailableAt(): string { return $this->availableAt; }
    public function getStartedAt(): ?string { return $this->startedAt; }
    public function getFinishedAt(): ?string { return $this->finishedAt; }
    public function getAttempts(): int { return $this->attempts; }
    public function getNote(): string { return $this->note; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): string { return $this->createdAt; }

    public function setJobKey(string $jobKey): void { $this->jobKey = $jobKey; }
    public function setPayload(array $payload): void { $this->payload = $payload; }
    public function setCursor(?array $cursor): void { $this->cursor = $cursor; }
    public function setAvailableAt(string $availableAt): void { $this->availableAt = $availableAt; }
    public function setStartedAt(?string $startedAt): void { $this->startedAt = $startedAt; }
    public function setFinishedAt(?string $finishedAt): void { $this->finishedAt = $finishedAt; }
    public function setAttempts(int|string $attempts): void { $this->attempts = (int)$attempts; }
    public function setNote(string $note): void { $this->note = $note; }
    public function setCreatedBy(string $createdBy): void { $this->createdBy = $createdBy; }
    public function setCreatedAt(string $createdAt): void { $this->createdAt = $createdAt; }

    public function setState(string $state): void
    {
        if (!in_array($state, [self::STATE_QUEUED, self::STATE_RUNNING, self::STATE_DONE, self::STATE_FAILED], true)) {
            throw new \InvalidArgumentException("Invalid job-run state '{$state}'");
        }
        $this->state = $state;
    }

    /** Waiting and its moment has come. */
    public function isDue(int $now): bool
    {
        if ($this->state !== self::STATE_QUEUED) {
            return false;
        }
        $available = $this->availableAt === '' ? 0 : strtotime($this->availableAt);

        return $available === false || $available <= $now;
    }

    /**
     * Marked running for longer than a runner pass can last — the process that
     * claimed it is gone. The caller MUST additionally confirm that no job lock
     * is held before treating it as an orphan; a long-running slice is not dead.
     */
    public function looksAbandoned(int $now, int $staleAfter): bool
    {
        if ($this->state !== self::STATE_RUNNING || $this->startedAt === null) {
            return false;
        }
        $started = strtotime($this->startedAt);

        return $started === false || ($now - $started) > $staleAfter;
    }
}
