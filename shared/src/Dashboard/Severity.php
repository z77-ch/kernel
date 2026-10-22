<?php

namespace Z77\Shared\Dashboard;

/**
 * How a pending entry of the backend dashboard weighs (ADR-052). Two weights
 * only (owner 2026-10-22): the dashboard shows what is to be DONE — a counter
 * that asks for nothing is not an entry at all.
 *
 *   - `Urgent` — «dringend»: something is already late or will block work
 *     (overdue invoices, no fiscal year for today, a failed job);
 *   - `Todo`   — «zu erledigen»: work that waits for someone (a dunning
 *     proposal, drafts to book, quotes to follow up).
 */
enum Severity: string
{
    case Urgent = 'urgent';
    case Todo   = 'todo';

    /** German, for the pill. */
    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'dringend',
            self::Todo   => 'zu erledigen',
        };
    }

    /** Sort key of the pending list: urgent first. */
    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 1,
            self::Todo   => 2,
        };
    }
}
