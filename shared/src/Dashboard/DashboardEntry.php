<?php

namespace Z77\Shared\Dashboard;

use Z77\Shared\Money\Money;

/**
 * One line a module hands the backend dashboard (ADR-052). Two kinds:
 *
 *   - **pending** — work that waits: a weight ({@see Severity}), a German
 *     label («Rechnungen überfällig»), optionally a count and an amount, and
 *     the page that shows it (route + query, e.g. the open-item list with
 *     `view=overdue`). It is a badge on its tile AND a row of the pending
 *     list below the tiles.
 *   - **create** — a «+ Neu» button in the head of its tile («Neuer
 *     Auftrag»), opened the way the list's own «+ Neu» opens it
 *     ({@see OPEN_PAGE}, {@see OPEN_WINDOW}, {@see OPEN_DIALOG}).
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

    /** How a create entry opens — the attribute the list's «+ Neu» carries. */
    public const OPEN_PAGE   = 'page';     // a plain link
    public const OPEN_WINDOW = 'window';   // `data-window-open` (ADR-047)
    public const OPEN_DIALOG = 'dialog';   // `data-fetch-get` (popup modal)

    private const OPENS = [self::OPEN_PAGE, self::OPEN_WINDOW, self::OPEN_DIALOG];

    /**
     * @param list<string>          $route [module, group, controller, action]; [] = no target (an unavailable line)
     * @param array<string, scalar> $query
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
     */
    public static function pending(Severity $severity, string $label, array $route, array $query = [], ?int $count = null, ?Money $amount = null): self
    {
        if ($route === []) {
            throw new \InvalidArgumentException("Dashboard entry «{$label}» needs a target route.");
        }

        return new self(self::KIND_PENDING, $severity, $label, $route, $query, $count, $amount, self::OPEN_PAGE);
    }

    /** @param list<string> $route the add action the list's «+ Neu» opens */
    public static function create(string $label, array $route, string $opens = self::OPEN_PAGE): self
    {
        if ($route === []) {
            throw new \InvalidArgumentException("Dashboard entry «{$label}» needs a target route.");
        }

        return new self(self::KIND_CREATE, null, $label, $route, [], null, null, $opens);
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
