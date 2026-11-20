<?php

namespace Z77\Shared\Site;

use Z77\Core\Services\ModuleManager;

/**
 * The public website's logo as the frontend brand mark shows it (2026-11-20, the mandator's logo
 * set-up window: «Frontend (Website)») — asked by `partials/brandMark` when the caller passes
 * `'siteLogo' => true` (module-frontend's default header and footer). The kernel knows no module:
 * ONE module declares a {@see SiteLogoSource} under the config key `siteLogo`
 * (module-mandator: `Logo\WebLogo`).
 *
 * An ADDRESS on this site, not inlined: the image is public content (the DMS's public area,
 * `/media/…?v=<checksum>`), so the browser caches it. Only a same-origin PATH is taken — `/x`, not
 * `//host/x`, not `https://…`, nothing with a quote or a space — so the page never loads an image
 * from elsewhere (CSP `img-src 'self'`, Rule 7).
 *
 * Never throws: no source, two sources, a source that fails, nothing stored, an address that is
 * not a plain local path → null, and the mark shows its wordmark. A failure is logged
 * (`site logo: …`).
 */
final class SiteLogo
{
    public const CONFIG_KEY = 'siteLogo';

    /**
     * @param string $url    a same-origin path (`/media/…`)
     * @param int    $width  display width in CSS pixels, 0 = unknown (the area's CSS sizes it)
     * @param int    $height display height in CSS pixels, 0 = unknown
     */
    public function __construct(
        public readonly string $url,
        public readonly string $alt = '',
        public readonly int $width = 0,
        public readonly int $height = 0,
    ) {
    }

    /** The registered source's logo — or null (see the class docblock). */
    public static function current(ModuleManager $modules): ?self
    {
        try {
            $class = BrandLogo::declaredSource($modules, self::CONFIG_KEY, SiteLogoSource::class);
            if ($class === null) {
                return null;
            }
            $logo = (new $class())->siteLogo();
            if (!$logo instanceof self) {
                return null;
            }
            if (!self::isLocalPath($logo->url)) {
                error_log('site logo: ' . $class . ' answered an address that is not a local path — not shown');

                return null;
            }

            return $logo;
        } catch (\Throwable $e) {
            error_log('site logo: ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }
    }

    /** `/…` on this site — never `//host`, a scheme, a backslash, a quote, `<`, `>` or whitespace. */
    public static function isLocalPath(string $url): bool
    {
        return preg_match('~^/(?![/\\\\])[^\s"\'<>\\\\]*$~', $url) === 1;
    }
}
