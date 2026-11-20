<?php

namespace Z77\Shared\Site;

use Z77\Core\Services\ModuleManager;
use Z77\Shared\Mail\InlineImage;

/**
 * The installation's logo as the brand mark shows it (owner 2026-11-20: the login shows the
 * mandator's logo, like the mails) — asked by `partials/brandMark` when the caller passes
 * `'logo' => true`. The kernel knows no module: ONE module declares a {@see BrandLogoSource}
 * under the config key `brandLogo` (module-mandator: `Logo\LoginLogo` — the login variant of the
 * logo set-up window, else its e-mail variant). The public website's logo is another
 * registry, {@see SiteLogo} (`siteLogo`, a `/media` address, not inlined).
 *
 * Delivered INLINE as a `data:` URI — no route and no file address: the login is public, and an
 * action serving a file to anyone would be one more door; this way nothing below `data/` or the
 * project is reachable, the page carries exactly the one image the source named. The page CSP
 * admits it (`img-src 'self' data:`, `SecurityHeaders`). Only a display-size file is inlined: up
 * to {@see MAX_BYTES}; a bigger one is not shown (logged) — the mandator's login / e-mail variant
 * is far below.
 *
 * Never throws: no source, two sources, a source that fails, nothing stored, a missing, unreadable,
 * non-PNG/JPEG/GIF or too large file → null, and the mark shows its wordmark. A failure is logged
 * (`brand logo: …`); the login never breaks over a logo.
 */
final class BrandLogo
{
    public const CONFIG_KEY = 'brandLogo';

    /** The largest file inlined into a page (raw bytes; the URI is a third larger). */
    public const MAX_BYTES = 96 * 1024;

    private function __construct(
        public readonly string $src,
        public readonly int $width,
        public readonly int $height,
        public readonly string $alt,
    ) {
    }

    /** The registered source's logo, inlined — or null (see the class docblock). */
    public static function current(ModuleManager $modules): ?self
    {
        try {
            $class = self::sourceClass($modules);
            if ($class === null) {
                return null;
            }
            $image = (new $class())->logo();

            return $image instanceof InlineImage ? self::inline($image) : null;
        } catch (\Throwable $e) {
            error_log('brand logo: ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }
    }

    /** The image as a `data:` URI with its own size — null when the file is too large or unreadable. */
    public static function inline(InlineImage $image): ?self
    {
        $size = @filesize($image->path);
        if ($size === false || $size < 1) {
            return null;
        }
        if ($size > self::MAX_BYTES) {
            error_log('brand logo: ' . basename($image->path) . ' has ' . intdiv($size, 1024) . ' KB — above '
                . intdiv(self::MAX_BYTES, 1024) . ' KB not inlined; store a display-size file');

            return null;
        }
        $info  = @getimagesize($image->path);
        $bytes = @file_get_contents($image->path);
        if (!is_array($info) || !in_array($info[2] ?? null, InlineImage::TYPES, true) || !is_string($bytes) || $bytes === '') {
            return null;
        }

        return new self(
            'data:' . image_type_to_mime_type($info[2]) . ';base64,' . base64_encode($bytes),
            $image->width,
            $image->height,
            $image->alt,
        );
    }

    /**
     * The ONE source the modules declare under `brandLogo` — a class that is no
     * {@see BrandLogoSource}, or two of them, is refused (thrown; {@see current()} logs it).
     *
     * @return class-string<BrandLogoSource>|null
     */
    public static function sourceClass(ModuleManager $modules): ?string
    {
        return self::declaredSource($modules, self::CONFIG_KEY, BrandLogoSource::class);
    }

    /**
     * The ONE class the modules declare under `$key`, implementing `$interface` — shared by the
     * brand logo (`brandLogo`) and the site logo (`siteLogo`, {@see SiteLogo}). A class that does
     * not implement it, or two different declarations, are refused (thrown).
     *
     * @internal the two logo registries of this namespace
     * @param class-string $interface
     * @return class-string|null
     */
    public static function declaredSource(ModuleManager $modules, string $key, string $interface): ?string
    {
        $found  = null;
        $origin = '';
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get($key, null);
            if ($declared === null || $declared === '') {
                continue;
            }
            if (!is_string($declared) || !class_exists($declared) || !is_subclass_of($declared, $interface)) {
                throw new \LogicException($key . " of module '{$moduleKey}' must name a class implementing " . $interface . '.');
            }
            if ($found !== null && $found !== $declared) {
                throw new \LogicException($key . " is declared twice (module '{$origin}' and module '{$moduleKey}') — one module answers the logo.");
            }
            $found  = $declared;
            $origin = (string) $moduleKey;
        }

        return $found;
    }
}
