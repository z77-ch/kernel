<?php

namespace Z77\Shared\Site;

use Z77\Core\DI;

/**
 * What this installation calls itself: `installationName` from systemConfig, or, unset (the seed),
 * the host of `canonicalBaseUrl`, which already differs per installation. Empty only where neither
 * is set.
 *
 * Read per call and not published as a constant like `CANONICAL_BASE_URL`: a cron entry needs that
 * one, this is a label. `cachePersist: false` for the same reason as in `Bootstrap`: an edited
 * systemConfig must show without «Cache leeren».
 */
final class InstallationLabel
{
    public static function current(): string
    {
        $name = trim((string) DI::getConfigManager()
            ->getBaseConfig(configName: 'config/systemConfig', throwError: false, cachePersist: false)
            ->getInstallationName('')
        );
        if ($name !== '') {
            return $name;
        }

        $base = defined('CANONICAL_BASE_URL') ? CANONICAL_BASE_URL : '';

        return $base === '' ? '' : (string) preg_replace('#^https?://#', '', $base);
    }
}
