<?php

namespace Z77\Shared\ProjectHealth;

use Z77\Shared\Dashboard\Severity;

/**
 * One DERIVED check of the installation (project-health bauplan, D1): computed from the data on
 * every dashboard load, never stored — fixed means gone. A module registers its checks under its
 * config key `projectChecks` ({@see ProjectChecks}); the bridge contributor of module-backend
 * (`ProjectHealthDashboard`) asks them and hands the open ones to the dashboard.
 *
 * Instantiated without arguments (persistence through `DI::getUnifiedEntityManager()`), ONE
 * instance per dashboard load. The bridge calls, in this order:
 *
 *   1. {@see id()}, {@see category()}, {@see order()}, {@see target()} — static facts, no I/O;
 *   2. {@see isOpen()} — ONLY when the user may reach the target, and for a setup check only while
 *      fewer than the configured number of steps are open before it (a setup step behind the
 *      limit is not even asked);
 *   3. {@see severity()} and {@see label()} — only after `isOpen()` answered true, so the label
 *      may name what `isOpen()` found (a check may keep that in a property).
 *
 * Rules for an implementation (the `DashboardContributor` rules, ADR-052):
 *   - `isOpen()` is cheap: ONE small query or file read (`SELECT 1 … LIMIT 1`, a COUNT, one
 *     `findOneBy()`); never load a list to decide;
 *   - the access check is the bridge's (`canReach()` on {@see target()}), never the check's;
 *   - a failure may throw: the bridge logs it and shows one line «Einrichtung: «{id}» nicht
 *     prüfbar» instead (it takes the check's place in the sequence).
 *
 * For a setup step extend {@see SetupCheck} (category Setup, severity todo): five methods.
 */
interface ProjectCheck
{
    /**
     * Stable and unique across all modules: `<module>.<what>` in kebab-case, e.g.
     * `financial.chart`. The key of a later «Nicht zutreffend» (bauplan D4) — never renamed.
     */
    public function id(): string;

    public function category(): CheckCategory;

    /**
     * The place in the SETUP sequence — the slot of the «Ersteinrichtung» table in
     * `docs/03-development/project-health-bauplan.md` (10 = Kontenplan … 120 = Eröffnung).
     * Lower first; equal values keep registration order. Ignored for a health check (return 0).
     */
    public function order(): int;

    /**
     * The screen that fixes it, `[module, group, controller, action]` as a navigation entry
     * stores it — the dashboard asks `canReach()` for it and places the line on the tile that
     * holds this page.
     *
     * @return list<string>
     */
    public function target(): array;

    /** Asked only after {@see isOpen()} answered true. */
    public function severity(): Severity;

    /**
     * One short German line, the fact or the step («Kontenplan übernehmen», «Mandant: Konten
     * zuweisen») — no explanation (that is the help, Rule 8). Asked only after {@see isOpen()}.
     */
    public function label(): string;

    /** True while the step is still to be done. Cheap (see the class docblock). */
    public function isOpen(): bool;
}
