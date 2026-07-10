<?php

namespace Z77\Shared\Libraries\Convention;

/**
 * LayoutDefaults
 *
 * Convention-level default names for the HTML layout system.
 * Holds the magic strings used by LayoutManager so they live in one place
 * instead of being buried as private constants in the manager.
 */
final class LayoutDefaults
{
    private function __construct() {}

    /**
     * Skeleton template used when no layoutConfig provides one (full page mode).
     */
    public const SKELETON = 'html-default-skeleton';

    /**
     * Skeleton template used for fetch-mode requests (AJAX fragments).
     */
    public const FETCH_SKELETON = 'html-fetch-skeleton';

    /**
     * Default name for a CSS entry in layoutConfig when 'name' is omitted.
     */
    public const STYLESHEET_NAME = 'stylesheet';
}
