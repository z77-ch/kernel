<?php

namespace Z77\Shared\Jobs;

/**
 * The long lock: one file per job key, held for the whole execution (ADR-031).
 *
 * Deliberately an OS lock rather than a state field in the record. flock() is
 * bound to the process, so a run killed by a fatal, a signal or a reboot frees
 * it automatically — a flag written into JSON would survive and block that job
 * forever. This is what makes the crash path self-healing.
 *
 * It is also what allows two unrelated jobs to run at the same time: a mailing
 * holds `mailing.lock` while a backup holds `backup-full.lock`. The short lock
 * on the queue file (FileStorage::withExclusiveLock) keeps the store to a single
 * writer independently of this one.
 *
 * Lock files are never deleted. Unlinking one would race a process already
 * blocked on its handle — the same reasoning as in FileStorage.
 */
final class JobLock
{
    /**
     * Reserved name for the short lock around picking the next entry. It cannot
     * be the queue file's own lock: the collection store takes that one on every
     * write through its own handle, and flock() contends per handle — a runner
     * holding it would block itself. A separate name guards the CHOICE, while
     * the store keeps guarding the FILE.
     */
    public const CLAIM = '_claim';

    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(private string $lockDir)
    {
        $this->lockDir = rtrim(str_replace('\\', '/', $lockDir), '/');
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
    }

    /**
     * Takes the lock for one job key, or reports that someone else holds it.
     * Non-blocking by design: a busy job is skipped, not waited for — the
     * runner has other entries to get on with.
     */
    public function acquire(string $jobKey): bool
    {
        if (isset($this->handles[$jobKey])) {
            return true;
        }

        $handle = @fopen($this->path($jobKey), 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open job lock for '{$jobKey}' in {$this->lockDir}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->handles[$jobKey] = $handle;
        return true;
    }

    /**
     * Waits a short budget for the lock instead of skipping — used for
     * {@see CLAIM}, where two runners starting in the same second must take
     * turns rather than both walk away. Polls non-blocking so a stuck holder
     * cannot hang the run forever.
     */
    public function acquireBlocking(string $jobKey, float $budgetSeconds = 2.0): bool
    {
        $deadline = microtime(true) + $budgetSeconds;
        do {
            if ($this->acquire($jobKey)) {
                return true;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(string $jobKey): void
    {
        if (!isset($this->handles[$jobKey])) {
            return;
        }
        flock($this->handles[$jobKey], LOCK_UN);
        fclose($this->handles[$jobKey]);
        unset($this->handles[$jobKey]);
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->handles) as $jobKey) {
            $this->release($jobKey);
        }
    }

    /**
     * Whether ANOTHER process is running this job. The only portable test is to
     * try the lock and give it straight back, so this must not be called while
     * this process holds it — hence the early return.
     */
    public function isHeldElsewhere(string $jobKey): bool
    {
        if (isset($this->handles[$jobKey])) {
            return false;
        }

        $path = $this->path($jobKey);
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return true;   // unreadable — assume busy rather than double-start
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /** How many of the given job keys are currently running elsewhere. */
    public function countHeldElsewhere(array $jobKeys): int
    {
        $held = 0;
        foreach ($jobKeys as $jobKey) {
            if ($this->isHeldElsewhere($jobKey)) {
                $held++;
            }
        }

        return $held;
    }

    private function path(string $jobKey): string
    {
        return $this->lockDir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $jobKey) . '.lock';
    }
}
