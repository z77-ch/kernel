<?php

namespace Z77\Shared\ProjectHealth;

use Z77\Shared\Dashboard\Severity;

/**
 * The base of a FIRST-SETUP step («Ersteinrichtung», owner 2026-11-19): category Setup, weight
 * todo — a setup step blocks nothing yet, it is the next thing to do. An implementation states
 * the five things that differ: {@see id()}, {@see order()}, {@see target()}, {@see label()},
 * {@see isOpen()}.
 *
 * ```php
 * final class ChartOfAccountsCheck extends SetupCheck
 * {
 *     public function id(): string     { return 'financial.chart'; }
 *     public function order(): int     { return 10; }
 *     public function target(): array  { return ['backend', 'finance', 'account', 'list']; }
 *     public function label(): string  { return 'Kontenplan übernehmen'; }
 *     public function isOpen(): bool   { return $this->accounts()->isEmpty(); }
 * }
 * ```
 */
abstract class SetupCheck implements ProjectCheck
{
    public function category(): CheckCategory
    {
        return CheckCategory::Setup;
    }

    public function severity(): Severity
    {
        return Severity::Todo;
    }
}
