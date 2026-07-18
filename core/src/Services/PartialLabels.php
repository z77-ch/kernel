<?php

namespace Z77\Core\Services;

use Z77\Core\DI,
    Z77\Core\Config\AuthRole
;

/**
 * Partial-label overlay — built-in dev tool showing which partial template
 * rendered each block of a page (orientation for developers/designers).
 *
 * Mechanics: when active, every rendered partial is wrapped in HTML comment
 * markers (`<!--z77p:partials/intro-->…<!--/z77p-->`) at the renderer level —
 * no template attributes, no project code. The framework overlay script
 * (`shared` asset `js/partial-labels.js`, auto-registered by LayoutManager)
 * walks the markers and floats a label over each partial's top-left corner.
 *
 * Activation gate (ALL required, PARTIAL-LABELS-002):
 *   1. DEBUG — load-bearing: under DEBUG the page cache is fully bypassed
 *      (PageCachePolicy), so markers + script can never be cached into pages
 *      served to visitors. Do not weaken this coupling. (Second, independent
 *      safety net: admin sessions never enter the shared page cache at all —
 *      CACHE-ADMIN-001.)
 *   2. Session user role >= admin.
 *   3. Per-user preference `partial_labels[viewArea]` on the LoginUser
 *      (UserPreferences), toggled in the frontend admin overlay. The view
 *      area is the request's module key (view-area invariant, ADR-022) —
 *      each user enables the overlay per area for themselves only.
 *
 * Inactive → output stays byte-identical (no markers, no script, not even inert).
 */
final class PartialLabels
{
    private static ?bool $active = null;

    /** Full runtime gate (DEBUG AND admin AND per-area preference), cached per request. */
    public static function active(): bool
    {
        if (self::$active !== null) {
            return self::$active;
        }

        if (!defined('DEBUG') || !DEBUG) {
            return self::$active = false;
        }

        try {
            $isAdmin = DI::getAuthService()
                ->getCurrentUser()
                ->hasAtLeast(AuthRole::ADMIN);

            if (!$isAdmin) {
                return self::$active = false;
            }

            return self::$active = DI::getCurrentUserService()
                ->getPreferences()
                ->isPartialLabelsEnabled(DI::getRequest()->getModule());
        } catch (\Throwable) {
            // No auth/session context (early boot, CLI) → inactive.
            return self::$active = false;
        }
    }

    /**
     * Wraps a partial's rendered output in the overlay markers. The name is
     * made comment-safe ('--' would terminate the HTML comment).
     */
    public static function wrap(string $name, string $html): string
    {
        $name = str_replace(['--', '>'], ['-', ''], $name);
        return '<!--z77p:' . $name . '-->' . $html . '<!--/z77p-->';
    }

    /**
     * Label name for a resolved absolute template path: the part after the
     * template directory without the .tpl.php suffix — the same form used in
     * partial() calls (e.g. `partials/intro`).
     */
    public static function nameFromPath(string $absPath): string
    {
        $path = str_replace('\\', '/', $absPath);
        $pos  = strrpos($path, '/templates/');
        $name = $pos !== false
            ? substr($path, $pos + strlen('/templates/'))
            : basename($path);

        return preg_replace('/\.tpl\.php$/', '', $name) ?? $name;
    }
}
