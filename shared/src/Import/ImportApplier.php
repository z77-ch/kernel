<?php

namespace Z77\Shared\Import;

use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Tree\TreeNode;
use Z77\Shared\Tree\TreeService;

/**
 * Writes the ACCEPTED entries of a plan into the target installation
 * (ADR-032). Only two outcomes are applicable:
 *
 *  - `new`:     insert — refs rewritten through the id map (matched targets +
 *               the ids of records inserted earlier in this run), `id` and
 *               `sort_key` assigned TARGET-side (IMP-R008), then validated and
 *               persisted. Parents before children (intra-class topo pass).
 *  - `changed`: overwrite the diffed CONTENT fields on the existing target
 *               record — never refs (reparenting is moveAction's turf) and
 *               never `sort_key`. This is also how key adoption lands: the
 *               `key` diff of an assigned record is a content field.
 *
 * Every write goes through the entity's validator (§9) — a rejection becomes
 * a `failed` result line, not a broken data file. Unclear/blocked/invalid
 * entries are never applicable; the caller must have replanned after the last
 * decision, so accepting them is a caller bug and reported as failed.
 */
final class ImportApplier
{
    /**
     * @param array<class-string, callable(object): ?object> $validatorFactories
     *        entity class → factory returning a validator exposing
     *        `isValid(): bool`, `getErrors(): array`, `getFieldErrors(): array`
     *        — or null when the entity has no validator.
     */
    public function __construct(
        private readonly UnifiedEntityManager $uem,
        private readonly ImportPlanner $planner,
        private readonly TreeService $treeService = new TreeService(),
        private array $validatorFactories = [],
    ) {}

    public function apply(ImportPlan $plan): ImportApplyResult
    {
        $result = new ImportApplyResult();

        // Seed the id map with every matched pair — refs of new records resolve
        // against it, and newly inserted ids join it as the run progresses.
        $idMap = [];
        foreach ($plan->entries as $entry) {
            $srcId = $entry->record['id'] ?? null;
            if ($entry->targetId !== null && $srcId !== null) {
                $idMap[$entry->entityClass][$srcId] = $entry->targetId;
            }
        }

        foreach ($plan->classOrder as $class) {
            $accepted = array_filter(
                $plan->entries,
                fn(ImportPlanEntry $e) => $e->entityClass === $class
                    && $e->decision === ImportPlanEntry::DECISION_ACCEPT
            );

            $newEntries = [];
            foreach ($accepted as $entry) {
                switch ($entry->outcome) {
                    case ImportOutcome::Changed:
                        $this->applyChanged($entry, $result);
                        break;
                    case ImportOutcome::NewRecord:
                        $newEntries[] = $entry;
                        break;
                    case ImportOutcome::Skipped:
                        break;   // accepting a no-op is harmless
                    default:
                        $result->add($entry, ImportApplyResult::FAILED,
                            "Outcome '{$entry->outcome->value}' is not applicable — recompute the plan after the last decision.");
                }
            }

            $this->applyNew($class, $newEntries, $idMap, $result);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Changed — content fields only, on the existing record
    // -------------------------------------------------------------------------

    private function applyChanged(ImportPlanEntry $entry, ImportApplyResult $result): void
    {
        $desc   = $this->planner->descriptor($entry->entityClass);
        $target = $this->uem->getRepository($entry->entityClass)->find($entry->targetId);
        if ($target === null) {
            $result->add($entry, ImportApplyResult::FAILED, "Target record #{$entry->targetId} no longer exists.");
            return;
        }

        $subset = [];
        foreach (array_keys($entry->diff) as $field) {
            if (in_array($field, $desc->getContentFields(), true)) {
                $subset[$field] = $entry->record[$field] ?? null;
            }
            // Ref diffs are shown, never applied — see class docblock.
        }
        if ($subset === []) {
            $result->add($entry, ImportApplyResult::APPLIED, 'Only ref differences — nothing applied (refs are never rewritten).');
            return;
        }

        $target->mapFromArray($subset);

        if (!$this->validate($entry, $target, $result)) return;

        $this->uem->persist($target);
        $this->uem->flush();
        $result->add($entry, ImportApplyResult::APPLIED, 'Updated: ' . implode(', ', array_keys($subset)));
    }

    // -------------------------------------------------------------------------
    // New — insert with rewritten refs, target-side id/sortKey
    // -------------------------------------------------------------------------

    /** @param list<ImportPlanEntry> $entries */
    private function applyNew(string $class, array $entries, array &$idMap, ImportApplyResult $result): void
    {
        $desc = $this->planner->descriptor($class);

        // Parents before children: repeatedly insert every entry whose same-run
        // refs are already resolvable. A leftover after a full silent pass has
        // an unresolvable dependency (declined/failed parent) and is reported.
        $pending = $entries;
        while ($pending !== []) {
            $progress = false;
            $stillPending = [];

            foreach ($pending as $entry) {
                if (!$this->refsResolvable($desc, $entry, $idMap)) {
                    $stillPending[] = $entry;
                    continue;
                }
                $this->insert($desc, $entry, $idMap, $result);
                $progress = true;
            }

            if (!$progress) {
                foreach ($stillPending as $entry) {
                    $result->add($entry, ImportApplyResult::FAILED,
                        'Unresolved dependency — a referenced record was declined or failed.');
                }
                return;
            }
            $pending = $stillPending;
        }
    }

    private function refsResolvable(ImportDescriptor $desc, ImportPlanEntry $entry, array $idMap): bool
    {
        foreach ($desc->getRefs() as $field => $ref) {
            $value = $entry->record[$field] ?? null;
            if ($value !== null && !isset($idMap[$ref->targetClass][$value])) {
                return false;
            }
        }
        return true;
    }

    private function insert(ImportDescriptor $desc, ImportPlanEntry $entry, array &$idMap, ImportApplyResult $result): void
    {
        $class = $desc->entityClass;

        // Hydrate from the source record WITHOUT id (target-side, IMP-R008) and
        // with every ref rewritten through the id map.
        $data = $entry->record;
        unset($data['id'], $data['sort_key']);
        foreach ($desc->getRefs() as $field => $ref) {
            $value = $data[$field] ?? null;
            $data[$field] = $value === null ? null : $idMap[$ref->targetClass][$value];
        }

        $entity = new $class($data);

        // Server-controlled fields bypass mapFromArray-able setters on purpose;
        // set what the hydration could not (ref fields have setters, parentId
        // does too — but sortKey must be the NEXT key in the target sibling group).
        if ($entity instanceof TreeNode) {
            $all = [];
            foreach ($this->uem->getRepository($class)->findAll() as $node) {
                $all[] = $node;
            }
            $entity->setSortKey($this->treeService->nextSortKey($all, $entity));
        }

        if (!$this->validate($entry, $entity, $result)) return;

        $this->uem->persist($entity);
        $this->uem->flush();

        $newId = $entity->getId();
        $srcId = $entry->record['id'] ?? null;
        if ($srcId !== null && $newId !== null) {
            $idMap[$class][$srcId] = $newId;
        }
        $result->add($entry, ImportApplyResult::APPLIED, 'Created as #' . $newId, $newId);
    }

    // -------------------------------------------------------------------------
    // Validation (§9 — no write bypasses the entity's validator)
    // -------------------------------------------------------------------------

    private function validate(ImportPlanEntry $entry, object $entity, ImportApplyResult $result): bool
    {
        $factory = $this->validatorFactories[$entry->entityClass] ?? null;
        if ($factory === null) return true;   // entity without a validator (documented contract)

        $validator = $factory($entity);
        if ($validator === null || $validator->isValid()) return true;

        $messages = array_merge(
            array_values($validator->getErrors()),
            array_map(
                fn(string $field, string $msg) => "{$field}: {$msg}",
                array_keys($validator->getFieldErrors()),
                array_values($validator->getFieldErrors())
            )
        );
        $result->add($entry, ImportApplyResult::FAILED, 'Validator rejected: ' . implode(' | ', $messages));
        return false;
    }
}
