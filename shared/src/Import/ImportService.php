<?php

namespace Z77\Shared\Import;

use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The facade the backend screen and the apply job talk to (ADR-032): loads
 * the target record sets, computes the plan (recomputed after every decision
 * — never patched), fingerprints the target state at plan time, and applies
 * under the staleness guard + the apply lock.
 */
final class ImportService
{
    public function __construct(
        private readonly UnifiedEntityManager $uem,
        private readonly ImportPlanner $planner,
        private readonly ImportApplier $applier,
        private readonly ImportStaging $staging,
        private readonly ImportPlanStore $planStore,
    ) {}

    public function getPlanner(): ImportPlanner { return $this->planner; }
    public function getStaging(): ImportStaging { return $this->staging; }
    public function getPlanStore(): ImportPlanStore { return $this->planStore; }

    /**
     * Computes the plan for a source against the CURRENT target data.
     *
     * @param array<string, array{decision?: string, target_id?: ?int}> $decisions
     * @return array{plan: ImportPlan, fingerprints: array<class-string, string>}
     */
    public function computePlan(ImportSource $source, array $decisions = []): array
    {
        $sourceSets = $source->recordSets();
        $targets    = $this->loadTargets(array_keys($sourceSets));

        return [
            'plan'         => $this->planner->plan($sourceSets, $targets, $decisions, $source->label()),
            'fingerprints' => $this->fingerprints($targets),
        ];
    }

    /**
     * Recomputes and applies under the apply lock. $expectedFingerprints is the
     * state the decisions were made against (from the plan store) — a mismatch
     * throws {@see ImportStaleException} instead of writing (IMP-R011).
     */
    public function apply(ImportSource $source, array $decisions, array $expectedFingerprints): ImportApplyResult
    {
        return $this->staging->withApplyLock(function () use ($source, $decisions, $expectedFingerprints): ImportApplyResult {
            $sourceSets = $source->recordSets();
            $targets    = $this->loadTargets(array_keys($sourceSets));

            $current = $this->fingerprints($targets);
            foreach ($expectedFingerprints as $class => $expected) {
                if (($current[$class] ?? null) !== $expected) {
                    throw new ImportStaleException(
                        "Target data for {$class} changed since the plan was computed — recompute the plan."
                    );
                }
            }

            $plan = $this->planner->plan($sourceSets, $targets, $decisions, $source->label());
            return $this->applier->apply($plan);
        });
    }

    /** @return array<class-string, list<object>> */
    public function loadTargets(array $classes): array
    {
        $targets = [];
        foreach ($classes as $class) {
            $targets[$class] = [];
            foreach ($this->uem->getRepository($class)->findAll() as $entity) {
                $targets[$class][] = $entity;
            }
        }
        return $targets;
    }

    /**
     * One hash per target record set — computed from the loaded records, not
     * from file bytes, so it works identically for every persistence driver.
     *
     * @param array<class-string, list<object>> $targets
     * @return array<class-string, string>
     */
    public function fingerprints(array $targets): array
    {
        $hashes = [];
        foreach ($targets as $class => $records) {
            $rows = array_map(fn(object $r) => $r->mapToArray(), $records);
            $hashes[$class] = sha1(json_encode($rows));
        }
        return $hashes;
    }
}
