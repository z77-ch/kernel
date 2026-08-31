<?php

namespace Z77\Shared\Import;

/**
 * The computed plan: every source record classified, in dependency order
 * (referenced entity classes before their dependents, parents before children
 * within a class). Recomputed — never patched — after a decision, because an
 * assignment can unblock dependents (ADR-032 §7).
 */
final class ImportPlan
{
    /**
     * @param list<ImportPlanEntry> $entries
     * @param list<class-string> $classOrder processing order the planner derived
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $classOrder,
        public readonly string $sourceLabel,
    ) {}

    /** @return array<string, int> outcome value → count, for the plan summary */
    public function summary(): array
    {
        $counts = [];
        foreach ($this->entries as $entry) {
            $counts[$entry->outcome->value] = ($counts[$entry->outcome->value] ?? 0) + 1;
        }
        return $counts;
    }

    /** @return list<ImportPlanEntry> */
    public function byOutcome(ImportOutcome $outcome): array
    {
        return array_values(array_filter(
            $this->entries,
            fn(ImportPlanEntry $e) => $e->outcome === $outcome
        ));
    }
}
