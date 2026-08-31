<?php

namespace Z77\Shared\Import;

/**
 * What the applier actually did, per accepted entry — the honest protocol the
 * screen shows after apply. `failed` entries were NOT written (validator
 * rejection, unresolved dependency); the rest of the run continues.
 */
final class ImportApplyResult
{
    public const APPLIED = 'applied';
    public const FAILED  = 'failed';

    /** @var list<array{entry: ImportPlanEntry, status: string, message: string, new_id: ?int}> */
    private array $lines = [];

    public function add(ImportPlanEntry $entry, string $status, string $message = '', ?int $newId = null): void
    {
        $this->lines[] = ['entry' => $entry, 'status' => $status, 'message' => $message, 'new_id' => $newId];
    }

    /** @return list<array{entry: ImportPlanEntry, status: string, message: string, new_id: ?int}> */
    public function getLines(): array { return $this->lines; }

    public function countApplied(): int
    {
        return count(array_filter($this->lines, fn(array $l) => $l['status'] === self::APPLIED));
    }

    public function countFailed(): int
    {
        return count(array_filter($this->lines, fn(array $l) => $l['status'] === self::FAILED));
    }
}
