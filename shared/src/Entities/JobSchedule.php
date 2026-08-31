<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * When a job should be queued (ADR-031). A schedule never runs anything — it
 * puts an entry into the queue, and the runner is the only thing that executes.
 * That keeps retry, locking, logging and the actor in one place instead of two
 * that drift apart.
 *
 * A module may ship a `defaultSchedule`, which is SEEDED once into a record
 * here. From then on the record belongs to the operator: switching it off or
 * changing the time must survive an update, so the seed is never re-applied.
 * A job that deletes data ships no default at all — it starts switched off by
 * having no schedule until someone creates one.
 */
#[Entity('file', 'framework/jobs/schedules.json')]
class JobSchedule
{
    use ArrayMappable;

    /** Server-controlled — no setter; the store assigns it. */
    private ?string $id = null;

    /** Registry key from a module's `jobs` config. */
    #[Clean('ident')]
    private string $jobKey = '';

    /** e.g. 'daily@03:15' — see {@see \Z77\Shared\Jobs\ScheduleExpression}. */
    #[Clean('text')]
    private string $expression = '';

    private bool $enabled = true;

    #[Clean('nullable', 'text')]
    private ?string $lastRunAt = null;

    #[Clean('text')]
    private string $nextRunAt = '';

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?string { return $this->id; }
    public function getJobKey(): string { return $this->jobKey; }
    public function getExpression(): string { return $this->expression; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getLastRunAt(): ?string { return $this->lastRunAt; }
    public function getNextRunAt(): string { return $this->nextRunAt; }

    public function setJobKey(string $jobKey): void { $this->jobKey = $jobKey; }
    public function setExpression(string $expression): void { $this->expression = $expression; }
    public function setEnabled(bool|int|string $enabled): void { $this->enabled = (bool) $enabled; }
    public function setLastRunAt(?string $lastRunAt): void { $this->lastRunAt = $lastRunAt; }
    public function setNextRunAt(string $nextRunAt): void { $this->nextRunAt = $nextRunAt; }

    /** Switched on and its moment has passed. */
    public function isDue(int $now): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $next = $this->nextRunAt === '' ? 0 : strtotime($this->nextRunAt);

        return $next === false || $next <= $now;
    }
}
