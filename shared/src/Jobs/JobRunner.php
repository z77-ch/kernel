<?php

namespace Z77\Shared\Jobs;

use Z77\Core\DI;
use Z77\Core\Services\ModuleManager;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Entities\JobRun;

/**
 * Works the queue for one pass and stops (ADR-031). Started by cron once a
 * minute through `vendor/bin/z77-run`; a pass with nothing due costs the boot
 * and exits.
 *
 * Two locks with different holding times are what let unrelated jobs overlap:
 * {@see JobLock::CLAIM} is held for the few milliseconds it takes to choose an
 * entry, while the per-job lock is held for the whole slice. A mailing and a
 * backup therefore run at the same time, and the store still only ever has one
 * writer.
 *
 * The time budget is advisory. A job is asked to stop via
 * {@see JobContext::hasTimeLeft()} and answers with {@see JobResult::again()};
 * one that never asks will overrun, which is logged and cannot be prevented
 * without pcntl.
 *
 * Due SCHEDULES are not read here yet — they will enqueue entries at the start
 * of a pass (phase 4 of the build plan) rather than become a second way to run
 * a job.
 */
final class JobRunner
{
    private const DEFAULT_MAX_PARALLEL = 3;
    private const DEFAULT_TIME_BUDGET  = 50;
    private const DEFAULT_STALE_AFTER  = 900;
    private const DEFAULT_KEEP_RUNS    = 50;
    private const MAX_BACKOFF          = 3600;

    /**
     * Entry ids already worked on in the current pass. An entry is picked up at
     * most once per pass: `again()` is the job stating it is finished FOR THIS
     * PASS, and the runner must not overrule that. Without this a job returning
     * `again(notBefore: 0)` is instantly due again and gets restarted in a tight
     * loop until the budget runs out — hundreds of no-op slices, each paying a
     * full read-modify-write on the store.
     *
     * @var array<string, true>
     */
    private array $handledThisPass = [];

    public function __construct(
        private ModuleManager $modules,
        private JobQueue $queue,
        private JobLock $locks,
        private array $config = [],
        private ?JobSchedules $schedules = null,
        private ?string $heartbeatFile = null,
    ) {
    }

    /**
     * What the last pass did, or null when none has ever run.
     *
     * The backend needs this: without it a queue full of waiting entries and a
     * cron line nobody ever added look exactly the same. An installation whose
     * heartbeat is old has no working cron, and that must be visible on the
     * screen where work is queued.
     *
     * @return array{at:string,summary:array}|null
     */
    public static function lastPass(string $baseDir): ?array
    {
        $file = rtrim(str_replace('\\', '/', $baseDir), '/') . '/data/framework/jobs/last-pass.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && isset($data['at']) ? $data : null;
    }

    /**
     * Builds the runner for an already-booted framework — the caller must have
     * run `Bootstrap::pullUpServices()`, which is where the entity manager and
     * module registry come from.
     */
    public static function fromBootedProject(string $baseDir): self
    {
        $baseDir    = rtrim(str_replace('\\', '/', $baseDir), '/');
        $configFile = $baseDir . '/config/jobs.inc.php';
        $config     = is_file($configFile) ? require $configFile : [];
        $uem        = DI::getInstance()->get('UnifiedEntityManager');

        return new self(
            DI::getModuleManager(),
            new JobQueue($uem),
            new JobLock($baseDir . '/data/framework/jobs/locks'),
            is_array($config) ? $config : [],
            new JobSchedules($uem),
            $baseDir . '/data/framework/jobs/last-pass.json',
        );
    }

    /**
     * One pass over the queue.
     *
     * @return array{executed:int,done:int,again:int,failed:int,reclaimed:int,seeded:int,queued:int,pruned:int,lines:list<string>}
     */
    public function run(?int $now = null): array
    {
        $now                   = $now ?? time();
        $deadline              = $now + $this->setting('timeBudget', self::DEFAULT_TIME_BUDGET);
        $this->handledThisPass = [];

        $summary = [
            'executed'  => 0,
            'done'      => 0,
            'again'     => 0,
            'failed'    => 0,
            'reclaimed' => $this->reclaimAbandoned(),
            'seeded'    => 0,
            'queued'    => 0,
            'pruned'    => 0,
            'lines'     => [],
        ];

        try {
            $this->runSchedules($summary, $now);

            while (time() < $deadline) {
                $claimed = $this->claim($deadline);
                if ($claimed === null) {
                    break;
                }
                [$entry, $definition] = $claimed;

                try {
                    $result = $this->execute($entry, $definition, $deadline);
                    $this->applyResult($entry, $definition, $result);

                    $summary['executed']++;
                    $summary[$result->isDone() ? 'done' : ($result->wantsMore() ? 'again' : 'failed')]++;
                    $summary['lines'][] = sprintf(
                        '%s: %s%s',
                        $entry->getJobKey(),
                        $result->getOutcome()->value,
                        $result->getNote() === '' ? '' : ' — ' . $result->getNote()
                    );
                } catch (\Throwable $e) {
                    // One broken job must not end the pass — the next entry still runs.
                    $this->applyResult($entry, $definition, JobResult::failed(
                        get_class($e) . ': ' . $e->getMessage()
                    ));
                    $summary['executed']++;
                    $summary['failed']++;
                    $summary['lines'][] = $entry->getJobKey() . ': crashed — ' . $e->getMessage();
                } finally {
                    $this->locks->release($entry->getJobKey());
                }

                if (time() >= $deadline) {
                    $summary['lines'][] = 'time budget spent — remaining work continues next pass';
                }
            }

            $summary['pruned'] = $this->queue->prune($this->setting('keepRuns', self::DEFAULT_KEEP_RUNS));
        } finally {
            $this->locks->releaseAll();
            $this->writeHeartbeat($summary);
        }

        return $summary;
    }

    /**
     * Seeds missing schedules and queues the due ones — the only place a
     * schedule ever touches the system. Both run under the claim lock so two
     * runners starting in the same second cannot seed or queue twice.
     *
     * A schedule problem (unreadable expression, job whose module is gone) is
     * reported and skipped: one bad config entry must not stop the pass.
     */
    private function runSchedules(array &$summary, int $now): void
    {
        if ($this->schedules === null || !$this->locks->acquireBlocking(JobLock::CLAIM)) {
            return;
        }

        try {
            $registry = $this->modules->getJobs();
            $problems = [];

            $summary['seeded'] = $this->schedules->seed($registry, $now, $problems);
            $summary['queued'] = $this->schedules->enqueueDue($this->queue, $registry, $now, $problems);

            foreach ($problems as $problem) {
                $summary['lines'][] = 'schedule problem — ' . $problem;
            }
        } finally {
            $this->locks->release(JobLock::CLAIM);
        }
    }

    /**
     * Puts entries whose process died back into the queue. The job lock is the
     * evidence — an entry sitting in 'running' while nobody holds its lock has
     * no process behind it. `attempts` is raised so a job that reliably kills
     * its own process cannot spin here forever.
     */
    private function reclaimAbandoned(?int $now = null): int
    {
        $stale     = $this->setting('staleAfter', self::DEFAULT_STALE_AFTER);
        $reclaimed = 0;

        foreach ($this->queue->abandoned($this->locks, $stale, $now) as $entry) {
            $entry->setState(JobRun::STATE_QUEUED);
            $entry->setStartedAt(null);
            $entry->setAttempts($entry->getAttempts() + 1);
            $entry->setNote('reclaimed — the run that claimed it died');
            $this->queue->save($entry);
            $reclaimed++;
        }

        return $reclaimed;
    }

    /**
     * Picks the next entry and marks it running, under the claim lock so two
     * runners starting in the same second cannot take the same one.
     *
     * @return array{0: JobRun, 1: array}|null
     */
    private function claim(int $deadline): ?array
    {
        if (!$this->locks->acquireBlocking(JobLock::CLAIM)) {
            return null;
        }

        try {
            $registry = $this->modules->getJobs();

            $running = $this->locks->countHeldElsewhere(array_keys($registry));
            if ($running >= $this->setting('maxParallel', self::DEFAULT_MAX_PARALLEL)) {
                return null;
            }

            foreach ($this->queue->due() as $entry) {
                if (isset($this->handledThisPass[(string) $entry->getId()])) {
                    continue;   // already had its slice in this pass
                }

                $definition = $registry[$entry->getJobKey()] ?? null;

                if ($definition === null) {
                    // The declaring module is gone — park it visibly instead of
                    // retrying a key nothing can resolve.
                    $entry->setState(JobRun::STATE_FAILED);
                    $entry->setFinishedAt(date(DATE_ATOM));
                    $entry->setNote("No module declares the job '{$entry->getJobKey()}'");
                    $this->queue->save($entry);
                    continue;
                }

                if (!$this->locks->acquire($entry->getJobKey())) {
                    continue;   // already running in another pass
                }

                $entry->setState(JobRun::STATE_RUNNING);
                $entry->setStartedAt(date(DATE_ATOM));
                $this->queue->save($entry);
                $this->handledThisPass[(string) $entry->getId()] = true;

                return [$entry, $definition];
            }

            return null;
        } finally {
            $this->locks->release(JobLock::CLAIM);
        }
    }

    private function execute(JobRun $entry, array $definition, int $deadline): JobResult
    {
        $class = $definition['class'];

        if (!class_exists($class)) {
            throw new \RuntimeException("Job class '{$class}' does not exist");
        }
        $job = new $class();
        if (!$job instanceof Job) {
            throw new \RuntimeException("Job class '{$class}' does not implement " . Job::class);
        }

        // The definition's payload sits UNDERNEATH the entry's, so a registry
        // entry can pin a value (the backup type) while a caller may still
        // override it per run.
        return $job->run(new JobContext(
            $entry->getJobKey(),
            array_merge($definition['payload'] ?? [], $entry->getPayload()),
            $entry->getCursor(),
            $entry->getAttempts() + 1,
            $deadline,
            $this->actorFor($entry->getJobKey(), $definition),
        ));
    }

    private function applyResult(JobRun $entry, array $definition, JobResult $result): void
    {
        $entry->setNote($result->getNote());

        if ($result->isDone()) {
            $entry->setState(JobRun::STATE_DONE);
            $entry->setFinishedAt(date(DATE_ATOM));
            $entry->setStartedAt($entry->getStartedAt());
            $this->queue->save($entry);

            return;
        }

        if ($result->wantsMore()) {
            if ($result->getCursor() !== null) {
                $entry->setCursor($result->getCursor());
            }
            $entry->setState(JobRun::STATE_QUEUED);
            $entry->setStartedAt(null);
            $entry->setAvailableAt(date(DATE_ATOM, time() + $result->getNotBefore()));
            $this->queue->save($entry);

            return;
        }

        $attempts = $entry->getAttempts() + 1;
        $entry->setAttempts($attempts);

        if ($attempts >= (int) ($definition['maxAttempts'] ?? 3)) {
            $entry->setState(JobRun::STATE_FAILED);
            $entry->setFinishedAt(date(DATE_ATOM));
        } else {
            $entry->setState(JobRun::STATE_QUEUED);
            $entry->setStartedAt(null);
            $entry->setAvailableAt(date(DATE_ATOM, time() + $this->backoff($attempts)));
        }

        $this->queue->save($entry);
    }

    /**
     * Records that a pass happened. Written in a `finally`, so a pass that died
     * halfway still leaves a mark — the point is to prove the cron fires, not
     * that everything went well. A plain file, not config: this is transient
     * runtime state, and a restore must not resurrect it (bootstrap.md).
     */
    private function writeHeartbeat(array $summary): void
    {
        if ($this->heartbeatFile === null) {
            return;
        }

        $dir = dirname($this->heartbeatFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents($this->heartbeatFile, json_encode([
            'at'      => date(DATE_ATOM),
            'summary' => array_diff_key($summary, ['lines' => null]),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Doubling wait after each failure, capped — a broken dependency is not fixed by hammering it. */
    private function backoff(int $attempts): int
    {
        return (int) min(self::MAX_BACKOFF, 60 * (2 ** max(0, $attempts - 1)));
    }

    /**
     * The identity a job acts under. Its realm is CRON, so nothing tries to
     * resolve it to a stored user; the role comes from the module config, where
     * a job needing more than the cron default has to say so visibly.
     */
    private function actorFor(string $jobKey, array $definition): AuthUser
    {
        return new AuthUser([
            'id'        => 'cron',
            'user_name' => 'cron:' . $jobKey,
            'roles'     => [$definition['runAs']],
            'realm'     => AuthUser::REALM_CRON,
        ]);
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }
}
