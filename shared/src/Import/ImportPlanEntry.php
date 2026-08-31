<?php

namespace Z77\Shared\Import;

/**
 * One source record's line in the plan: what the matcher concluded and — once
 * the developer decided — what to do about it. Pure data; the planner fills
 * the conclusion, the screen fills the decision, the applier reads both.
 */
final class ImportPlanEntry
{
    public const DECISION_PENDING = 'pending';
    public const DECISION_ACCEPT  = 'accept';
    public const DECISION_REJECT  = 'reject';

    /**
     * @param class-string $entityClass
     * @param array<string, mixed> $record  normalized source record (snake_case, id attached)
     * @param array<string, array{source: mixed, target: mixed}> $diff  content fields that
     *        differ on a Changed entry; ref fields appear with their raw values plus a
     *        resolved marker in the reason text
     * @param ?int $targetId      matched target record (Skipped/Changed)
     * @param ?int $suggestionId  near-match suggestion (Unclear)
     * @param ?int $blockedByIndex plan index (same source) of the record blocking this one
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly int $sourceIndex,
        public readonly array $record,
        public ImportOutcome $outcome,
        public ?int $targetId = null,
        public ?int $suggestionId = null,
        public array $diff = [],
        public string $reason = '',
        public ?int $blockedByIndex = null,
        public string $decision = self::DECISION_PENDING,
        public ?int $assignedTargetId = null,
    ) {}

    /** Short display handle for logs and the plan screen. */
    public function describe(): string
    {
        $name = $this->record['name'] ?? $this->record['path'] ?? ('#' . ($this->record['id'] ?? $this->sourceIndex));
        return (new \ReflectionClass($this->entityClass))->getShortName() . ' «' . $name . '»';
    }
}
