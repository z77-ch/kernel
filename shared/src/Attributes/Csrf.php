<?php

namespace Z77\Shared\Attributes;

/**
 * Declares that the action requires a valid CSRF token on write methods
 * (non-GET/HEAD). Enforced by AccessGuard::enforce() BEFORE the action runs:
 * token from the X-CSRF-Token header (fetch convention) or the `csrf_token`
 * body field (page-mode form convention — the field name matches the
 * $csrfToken context variable every html() render provides).
 *
 * Failure: Fetch mode → error envelope (same as the global fetch check);
 * Page mode → 303 redirect to the site root (no message channel — actions
 * that want a friendly failure UX validate in-action instead, e.g. the
 * zihlundsee contact form).
 *
 * Note: fetch POSTs are CSRF-checked globally regardless — this attribute
 * exists for page-mode POST endpoints (form posts).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Csrf
{
}
