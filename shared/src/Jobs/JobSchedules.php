<?php

namespace Z77\Shared\Jobs;

use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Entities\JobSchedule;

/**
 * The schedule store (ADR-031). Its whole job is to turn "it is 03:15" into a
 * queue entry — it never executes anything itself.
 *
 * Seeding is one-way. A module's `defaultSchedule` creates the record the first
 * time the runner sees an unknown job key; after that the record belongs to the
 * operator and the seed is never applied again. Otherwise every update would
 * silently switch a schedule back on that someone deliberately turned off.
 */
final class JobSchedules
{
    public function __construct(private UnifiedEntityManager $uem)
    {
    }

    /** @return list<JobSchedule> */
    public function all(): array
    {
        $schedules = [];
        foreach ($this->uem->getRepository(JobSchedule::class)->findAll() as $schedule) {
            if ($schedule instanceof JobSchedule) {
                $schedules[] = $schedule;
            }
        }

        return $schedules;
    }

    public function findByJobKey(string $jobKey): ?JobSchedule
    {
        foreach ($this->all() as $schedule) {
            if ($schedule->getJobKey() === $jobKey) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * Creates the records for jobs that declare a `defaultSchedule` and have no
     * record yet. Jobs without one — anything that deletes, by convention — get
     * nothing: no record means nothing is scheduled.
     *
     * An unreadable expression is skipped rather than fatal: one bad module
     * config must not stop every other job from running. The caller receives
     * the problems so a pass can report them.
     *
     * @param array<string, array> $registry ModuleManager::getJobs()
     * @param list<string>         $problems out-param for unreadable expressions
     * @return int how many records were created
     */
    public function seed(array $registry, ?int $now = null, array &$problems = []): int
    {
        $now    = $now ?? time();
        $seeded = 0;

        foreach ($registry as $jobKey => $definition) {
            $expression = $definition['defaultSchedule'] ?? null;
            if ($expression === null || $expression === '') {
                continue;
            }
            if ($this->findByJobKey($jobKey) !== null) {
                continue;   // the operator owns it now
            }
            if (!ScheduleExpression::isValid($expression)) {
                $problems[] = "job '{$jobKey}': defaultSchedule '{$expression}' is not readable";
                continue;
            }

            $schedule = new JobSchedule();
            $this->assignId($schedule);
            $schedule->setJobKey($jobKey);
            $schedule->setExpression($expression);
            $schedule->setEnabled(true);
            $schedule->setNextRunAt(date(DATE_ATOM, ScheduleExpression::parse($expression)->nextAfter($now)));
            $this->save($schedule);
            $seeded++;
        }

        return $seeded;
    }

    /**
     * Queues one entry per due schedule and moves each to its next moment.
     *
     * A schedule whose job still has unfinished work is moved on WITHOUT
     * queueing: a nightly sweep that outlasts the night must not stack up a
     * second copy of itself. The dedup is per job key, not per schedule, so a
     * hand-queued entry blocks it too — deliberately, since both end up in the
     * same runner.
     *
     * @param array<string, array> $registry ModuleManager::getJobs()
     * @param list<string>         $problems out-param for unreadable expressions
     * @return int how many entries were queued
     */
    public function enqueueDue(JobQueue $queue, array $registry, ?int $now = null, array &$problems = []): int
    {
        $now    = $now ?? time();
        $queued = 0;

        foreach ($this->all() as $schedule) {
            if (!$schedule->isDue($now)) {
                continue;
            }
            if (!isset($registry[$schedule->getJobKey()])) {
                $problems[] = "schedule for '{$schedule->getJobKey()}': no module declares that job";
                continue;
            }

            try {
                $expression = ScheduleExpression::parse($schedule->getExpression());
            } catch (\InvalidArgumentException $e) {
                $problems[] = "schedule for '{$schedule->getJobKey()}': " . $e->getMessage();
                continue;
            }

            if (!$queue->hasOpenEntry($schedule->getJobKey())) {
                $queue->enqueue($schedule->getJobKey(), [], 'schedule', 0, $now);
                $queued++;
            }

            $lastRun = $now;
            $schedule->setLastRunAt(date(DATE_ATOM, $lastRun));
            $schedule->setNextRunAt(date(DATE_ATOM, $expression->nextAfter($now, $lastRun)));
            $this->save($schedule);
        }

        return $queued;
    }

    /**
     * A new record for a job that had none — the operator setting a schedule on
     * something a module shipped without one. Not persisted here; the caller
     * sets `nextRunAt` and saves.
     */
    public function create(string $jobKey, string $expression): JobSchedule
    {
        $schedule = new JobSchedule();
        $this->assignId($schedule);
        $schedule->setJobKey($jobKey);
        $schedule->setExpression($expression);
        $schedule->setEnabled(true);

        return $schedule;
    }

    public function save(JobSchedule $schedule): void
    {
        $this->uem->persist($schedule);
        $this->uem->flush();
    }

    public function delete(JobSchedule $schedule): void
    {
        $this->uem->remove($schedule);
        $this->uem->flush();
    }

    private function assignId(JobSchedule $schedule): void
    {
        $ref = new \ReflectionProperty(JobSchedule::class, 'id');
        $ref->setValue($schedule, 's-' . bin2hex(random_bytes(8)));
    }
}
