<?php

namespace Z77\Shared\Jobs;

use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Entities\JobRun;

/**
 * The store behind the queue (ADR-031). Enqueueing is the only thing the
 * application ever does with a job — execution belongs to {@see JobRunner},
 * which is why a backend click cannot run into a request timeout.
 *
 * Writes go through the entity manager, which locks the file itself; this class
 * adds no lock of its own. The one operation that needs more than a single
 * write — choosing an entry and marking it running — is guarded by the runner
 * under {@see JobLock::CLAIM}.
 */
final class JobQueue
{
    public function __construct(private UnifiedEntityManager $uem)
    {
    }

    /**
     * Puts one piece of work into the queue.
     *
     * @param int $delaySeconds how long before it may be picked up (0 = at once)
     */
    public function enqueue(
        string $jobKey,
        array $payload = [],
        string $createdBy = 'cron',
        int $delaySeconds = 0,
        ?int $now = null
    ): JobRun {
        $now ??= time();

        $run = new JobRun();
        $this->assignId($run);
        $run->setJobKey($jobKey);
        $run->setPayload($payload);
        $run->setCreatedBy($createdBy);
        $run->setCreatedAt(date(DATE_ATOM, $now));
        $run->setAvailableAt(date(DATE_ATOM, $now + max(0, $delaySeconds)));

        $this->save($run);

        return $run;
    }

    /** @return list<JobRun> every entry, newest last */
    public function all(): array
    {
        $runs = [];
        foreach ($this->uem->getRepository(JobRun::class)->findAll() as $run) {
            if ($run instanceof JobRun) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * Queued entries whose moment has come, oldest first — so a job that has
     * been waiting does not starve behind one that was just enqueued.
     *
     * @return list<JobRun>
     */
    public function due(?int $now = null): array
    {
        $now = $now ?? time();
        $due = array_values(array_filter($this->all(), static fn(JobRun $r) => $r->isDue($now)));

        usort($due, static fn(JobRun $a, JobRun $b) => strcmp($a->getAvailableAt(), $b->getAvailableAt()));

        return $due;
    }

    /**
     * Whether this job already has unfinished work in the queue — the guard
     * against a schedule stacking up entries while an earlier one is still
     * being worked through.
     */
    public function hasOpenEntry(string $jobKey): bool
    {
        foreach ($this->all() as $run) {
            if ($run->getJobKey() !== $jobKey) {
                continue;
            }
            if (in_array($run->getState(), [JobRun::STATE_QUEUED, JobRun::STATE_RUNNING], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entries stuck in 'running' from a process that no longer exists. The lock
     * is the deciding evidence: a slice that is genuinely still working holds
     * it, however long it takes. The age check alone would kill live work.
     *
     * @return list<JobRun>
     */
    public function abandoned(JobLock $locks, int $staleAfter, ?int $now = null): array
    {
        $now  = $now ?? time();
        $dead = [];
        foreach ($this->all() as $run) {
            if ($run->looksAbandoned($now, $staleAfter) && !$locks->isHeldElsewhere($run->getJobKey())) {
                $dead[] = $run;
            }
        }

        return $dead;
    }

    /**
     * Keeps the newest $keepPerJob finished entries per job and drops the rest.
     * Without this the file grows forever and every run pays for it on load.
     *
     * @return int how many were removed
     */
    public function prune(int $keepPerJob): int
    {
        $finished = [];
        foreach ($this->all() as $run) {
            if (in_array($run->getState(), [JobRun::STATE_DONE, JobRun::STATE_FAILED], true)) {
                $finished[$run->getJobKey()][] = $run;
            }
        }

        $removed = 0;
        foreach ($finished as $runs) {
            if (count($runs) <= $keepPerJob) {
                continue;
            }
            usort($runs, static fn(JobRun $a, JobRun $b) => strcmp(
                (string) $b->getFinishedAt(),
                (string) $a->getFinishedAt()
            ));
            foreach (array_slice($runs, $keepPerJob) as $old) {
                $this->uem->remove($old);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->uem->flush();
        }

        return $removed;
    }

    public function save(JobRun $run): void
    {
        $this->uem->persist($run);
        $this->uem->flush();
    }

    public function delete(JobRun $run): void
    {
        $this->uem->remove($run);
        $this->uem->flush();
    }

    /** Server-controlled id, mirroring the pattern used by the member stores. */
    private function assignId(JobRun $run): void
    {
        $ref = new \ReflectionProperty(JobRun::class, 'id');
        $ref->setValue($run, 'j-' . bin2hex(random_bytes(8)));
    }
}
