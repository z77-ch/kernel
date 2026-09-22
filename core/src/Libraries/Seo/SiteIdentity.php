<?php

namespace Z77\Core\Libraries\Seo;

/**
 * Who the site is, for the document head: name, author, the Open Graph locale
 * per language and the link-preview image. Read from the module config block
 * `site` of the requested module (a project overrides `<module>Config.inc.php`):
 *
 *   'site' => [
 *       'name'    => 'Zihl & See',                 // og:site_name, <title> fallback
 *       'author'  => 'sihlestate AG',              // <meta name="author">
 *       'locales' => ['de' => 'de_CH', 'fr' => 'fr_CH'],   // og:locale per language
 *       'image'   => [                             // og:image / twitter:image
 *           'path'   => '/assets/frontend/images/og.jpg',  // site-relative or absolute
 *           'width'  => 1200,
 *           'height' => 630,
 *           'alt'    => ['de' => '…', 'fr' => '…'],        // per language
 *       ],
 *   ],
 *
 * Every entry is optional, and a missing one leaves its tag OUT — the framework
 * ships no placeholder (it used to print "Max Mustermann" and example.com on
 * every page of every site that did not override the partials).
 *
 * Built once per page render in AbstractBaseController::html() and handed to
 * the head partials as `$site`; values for the request language are resolved
 * here, so the templates only print.
 */
final class SiteIdentity
{
    private function __construct(
        public readonly string $name,
        public readonly string $author,
        public readonly string $locale,
        public readonly string $imageUrl,
        public readonly int $imageWidth,
        public readonly int $imageHeight,
        public readonly string $imageAlt,
    ) {}

    /**
     * @param array  $config   the module's `site` block ([] when absent)
     * @param string $language request language
     * @param string $origin   absolute origin for a site-relative image path
     *                         (Request::getBaseUrl(), never the Host header)
     */
    public static function fromConfig(array $config, string $language, string $origin): self
    {
        $image = is_array($config['image'] ?? null) ? $config['image'] : [];
        $path  = self::string($image['path'] ?? '');

        return new self(
            name:        self::string($config['name'] ?? ''),
            author:      self::string($config['author'] ?? ''),
            locale:      self::perLanguage($config['locales'] ?? [], $language),
            imageUrl:    ($path !== '' && $path[0] === '/' && !str_starts_with($path, '//')) ? $origin . $path : $path,
            imageWidth:  (int)($image['width'] ?? 0),
            imageHeight: (int)($image['height'] ?? 0),
            imageAlt:    self::perLanguage($image['alt'] ?? [], $language),
        );
    }

    /** No `site` block at all — every tag that depends on it is left out. */
    public static function empty(): self
    {
        return new self('', '', '', '', 0, 0, '');
    }

    /** A value per language: the request language's entry, '' if none (no guessing). */
    private static function perLanguage(mixed $map, string $language): string
    {
        if (is_string($map)) {
            return trim($map);
        }

        return is_array($map) ? self::string($map[$language] ?? '') : '';
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
