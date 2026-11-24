<?php

namespace Z77\Shared\Navigation;

/**
 * Whether a backend menu entry makes sense for THIS installation's data — the
 * data half of the menu rule, next to the access half (`AuthService::canReach()`,
 * ADR-045 §2). Example: «MWST-Abrechnungen» means nothing for a mandator that
 * settles no VAT (module-financial, `VatReturnMenuCondition`).
 *
 * Registered by class name under the module config key `menuConditions`, keyed by
 * the ROUTE the entry leads to (see {@see MenuConditions}); instantiated without
 * arguments, so a condition reaches persistence the way every service does
 * (`DI::getUnifiedEntityManager()`).
 *
 * Rules for an implementation:
 *   - CHEAP: the menu is built on every backend page. Read what the request
 *     already holds or ONE record by id — never a list, never a COUNT over a
 *     journal. The registry asks a condition at most once per request;
 *   - answer «visible» in every case the page itself can explain (a setting not
 *     made yet, a method not supported) — hide only when the page has nothing
 *     to offer at all;
 *   - it hides a MENU entry, nothing else: the page keeps answering a typed URL
 *     with its own message, AccessGuard keeps deciding access;
 *   - a failure may throw: the registry logs it and shows the entry.
 */
interface MenuCondition
{
    /** False = the entry is left out of the backend menu. */
    public function visible(): bool;
}
