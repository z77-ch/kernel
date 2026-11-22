<?php

namespace Z77\Shared\Dashboard;

use Z77\Shared\Money\Money;

/**
 * One line a module hands the backend dashboard (ADR-052). Three kinds:
 *
 *   - **pending** — work that waits: a weight ({@see Severity}), a German
 *     label («Rechnungen überfällig»), optionally a count and an amount, and
 *     the page that shows it (route + query, e.g. the open-item list with
 *     `view=overdue`). It is a badge on its tile AND a row of the pending
 *     list below the tiles.
 *   - **create** — a «+ Neu» button in the head of its tile («Neuer
 *     Auftrag»), opened the way the list's own «+ Neu» opens it
 *     ({@see OPEN_PAGE}, {@see OPEN_WINDOW}, {@see OPEN_DIALOG}).
 *   - **figure** — a stock number that is NOT open work («12 Mandanten»,
 *     ADR-052 addendum 2026-11-22): part of the ONE quiet sub line of its
 *     tile, never in the pending list, no weight.
 *
 * The route is `[module, group, controller, action]` as a navigation entry
 * stores it (`action` '' = the controller's default). It is the ONE thing
 * that places the entry: the dashboard asks `canReach()` for it and finds the
 * tile whose menu section holds that page. A module never names a section.
 */
final class DashboardEntry
{
    public const KIND_PENDING = 'pending';
    public const KIND_CREATE  = 'create';
    public const KIND_FIGURE  = 'figure';

    /** How a create entry opens — the attribute the list's «+ Neu» carries. */
    public const OPEN_PAGE   = 'page';     // a plain link
    public const OPEN_WINDOW = 'window';   // `data-window-open` (ADR-047)
    public const OPEN_DIALOG = 'dialog';   // `data-fetch-get` (popup modal)

    private const OPENS = [self::OPEN_PAGE, self::OPEN_WINDOW, self::OPEN_DIALOG];

    /**
     * @param list<string>          $route  [module, group, controller, action]; [] = no target (an unavailable line)
     * @param array<string, scalar> $query
     * @param bool                  $linked false = the entry is shown as plain text; the route still places it
     *                                      and decides who sees it (only a figure may be unlinked)
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?Severity $severity,
        public readonly string $label,
        public readonly array $route,
        public readonly array $query,
        public readonly ?int $count,
        public readonly ?Money $amount,
        public readonly string $opens,
        public readonly bool $linked = true,
    ) {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('A dashboard entry needs a label.');
        }
        if ($route !== []) {
            if (!array_is_list($route) || count($route) !== 4 || array_filter($route, 'is_string') !== $route
                || $route[0] === '' || $route[1] === '' || $route[2] === '') {
                throw new \InvalidArgumentException("Dashboard entry «{$label}»: the route must be [module, group, controller, action] (action may be '').");
            }
        }
        if (!in_array($opens, self::OPENS, true)) {
            throw new \InvalidArgumentException("Dashboard entry «{$label}»: unknown opening '{$opens}'.");
        }
    }

    /**
     * @param list<string>          $route [module, group, controller, action]
     * @param array<string, scalar> $query the target list's filter, e.g. ['view' => 'overdue']
     * @param string                $opens how the line opens its target (2026-11-20): {@see OPEN_PAGE} (a plain link,
     *                                     the default) or {@see OPEN_WINDOW} (`data-window-open`, ADR-047 — the
     *                                     target answers as a window; without the script the link opens it as a page).
     *                                     First user: the setup step «Firmenlogo … hinterlegen» opens the logo window.
     */
    public static function pending(Severity $severity, string $label, array $route, array $query = [], ?int $count = null, ?Money $amount = null, string $opens = self::OPEN_PAGE): self
    {
        if ($route === []) {
            throw new \InvalidArgumentException("Dashboard entry «{$label}» needs a target route.");
        }
        if ($opens === self::OPEN_DIALOG) {
            throw new \InvalidArgumentException("Dashboard entry «{$label}»: a pending line opens as a page or a window, not as a dialog.");
        }

        return new self(self::KIND_PENDING, $severity, $label, $route, $query, $count, $amount, $opens);
    }

    /** @param list<string> $route the add action the list's «+ Neu» opens */
    public static function create(string $label, array $route, string $opens = self::OPEN_PAGE): self
    {
        if ($route === []) {
            throw new \InvalidArgumentException("Dashboard entry «{$label}» needs a target route.");
        }

        return new self(self::KIND_CREATE, null, $label, $route, [], null, null, $opens);
    }

    /**
     * A stock number that is not open work (owner 2026-11-22): the tile shows «{count} {label}» in its
     * one sub line. The contributor hands the finished label — singular or plural is its choice
     * («1 Mandant», «12 Mandanten», «2 ruhend»).
     *
     * @param list<string>          $route the page the number belongs to: it places the figure on a tile and
     *                                     decides who sees it (`canReach()`), like every entry
     * @param array<string, scalar> $query the target list's filter when the figure links, e.g. ['view' => 'dormant']
     * @param bool                  $link  false = plain text, no link (the route still places and filters)
     */
    public static function figure(string $label, int $count, array $route, array $query = [], bool $link = true): self
    {
        if ($route === []) {
            throw new \InvalidArgumentException("Dashboard entry «{$label}» needs a target route.");
        }

        return new self(self::KIND_FIGURE, null, $label, $route, $query, $count, null, self::OPEN_PAGE, $link);
    }

    /** The line that replaces a contributor's entries when it failed — no target, so no tile and no link. */
    public static function unavailable(string $label): self
    {
        return new self(self::KIND_PENDING, Severity::Todo, $label, [], [], null, null, self::OPEN_PAGE);
    }

    public function isPending(): bool
    {
        return $this->kind === self::KIND_PENDING;
    }

    public function hasTarget(): bool
    {
        return $this->route !== [];
    }

    /** The backend URL of the target (4-segment form) with its query; '' without a target. */
    public function href(): string
    {
        if ($this->route === []) {
            return '';
        }
        $path = '/' . implode('/', array_filter($this->route, static fn(string $s) => $s !== ''));
        $qs   = http_build_query($this->query);

        return $qs === '' ? $path : $path . '?' . $qs;
    }
}
