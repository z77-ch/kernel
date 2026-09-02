<?php

namespace Z77\Shared\Libraries;

/**
 * Locates an installation config file in the split layout (ADR-036):
 *
 *   config/vendor/<file>   installer-GENERATED (bootstrap, fileFinder,
 *                          moduleManager) — a function of composer.json +
 *                          vendor/, owned by the RELEASE like vendor/ itself
 *   config/client/<file>   hand-maintained machine/project facts (systemConfig,
 *                          mail, i18n, auth, backup, geoip) — owned by the
 *                          INSTALLATION; in the release layout `config/client`
 *                          is a symlink into shared/
 *   config/<file>          legacy flat layout — fallback so an installation
 *                          that predates the split keeps working until its
 *                          next `composer install` migrates it
 *
 * The search order doubles as the authority order: a file that exists in a
 * split directory shadows a flat leftover.
 */
final class ConfigLocator
{
    /** First existing path for the file, or null. */
    public static function path(string $fileName): ?string
    {
        $base = rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/config/';
        foreach (['vendor/', 'client/', ''] as $tier) {
            $candidate = $base . $tier . $fileName;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
