<?php

namespace Z77\Shared\Dashboard;

use Z77\Shared\Auth\AuthUser;

/**
 * What a module contributes to the backend dashboard (ADR-052): its pending
 * work and its «+ Neu» buttons, as {@see DashboardEntry} values.
 *
 * Registered by class name under the module config key `dashboard` (see
 * {@see DashboardContributors}); instantiated without arguments, so a
 * contributor reaches persistence the way every service does
 * (`DI::getUnifiedEntityManager()`).
 *
 * Rules for an implementation:
 *   - ONE cheap query per entry (a COUNT or a SUM) — never load a list to
 *     count it; the dashboard is the page every user opens first;
 *   - yield nothing when nothing is to be done (no «0 offen» entries);
 *   - name the TARGET page only — never a menu section: the dashboard finds
 *     the tile from the menu, and the access check is the dashboard's
 *     (`AuthService::canReach()` on the target), not the contributor's;
 *   - a failure may throw: the dashboard logs it and shows one line
 *     «… nicht verfügbar» instead of this contributor's entries.
 */
interface DashboardContributor
{
    /** @return iterable<DashboardEntry> */
    public function entries(AuthUser $user): iterable;
}
