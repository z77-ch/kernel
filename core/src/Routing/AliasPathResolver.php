<?php

namespace Z77\Core\Routing;

use Z77\Core\Services\I18n;
use Z77\Core\Services\SlugTranslator;
use Z77\Shared\Entities\Navigation;

/**
 * The ONE place where URL slug translation meets the alias layer (ADR-014 +
 * ADR-015, amended 2026-09-17) — both directions, so that every URL
 * {@see toLocalized()} emits is one {@see resolve()} reads back to the same page.
 *
 * The rule: slug translation applies to the ALIAS PART of a URL and to nothing
 * else. An alias is the public stand-in of a navigation entry; its path is stored in
 * the default language, and the slug table says how that path reads in another
 * language. Everything that is not an alias path is written as it is resolved:
 *
 *   - technical paths (4-tuple navigation path, convention route, Fetch) are never
 *     translated — a localized word that happens to equal a controller name
 *     (`kontakt → contact` vs. `ContactController`) can no longer break them;
 *   - the remainder behind a slug-accepting alias (`/referenzen/mein_name`) is
 *     entity content, not URL structure: it passes through raw, both ways.
 *
 * Pure: no Request, no session, no output. Used by `Request::runParsing()` (inbound)
 * and the `localizedUrl()` helper (outbound).
 */
class AliasPathResolver
{
    public function __construct(
        private Router         $router,
        private SlugTranslator $slugTranslator,
        private I18n           $i18n
    ) {}

    /**
     * Inbound: resolves request segments (language prefix already stripped) against
     * the alias table. Non-default language: the segments are translated to canonical
     * first; the raw spelling is tried as well, translated first ({@see
     * \Z77\Core\Services\NavigationUrlResolver::matchAlias()}).
     *
     * `canonical` is the matched URL in the default language (alias path + slugs);
     * `localized` is the single indexable form of the matched URL in `$language` —
     * the caller 301s to it when the request was spelled differently.
     *
     * @param list<string> $segments request path segments as requested (raw)
     * @return array{navigation: Navigation, slugs: list<string>, canonical: list<string>, localized: list<string>}|null
     *         null = not an alias URL: resolve the raw segments as written
     */
    public function resolve(array $segments, string $language): ?array
    {
        if ($segments === []) {
            return null;
        }

        $isDefault = $language === $this->i18n->getDefaultLanguage();
        $canonical = $isDefault ? $segments : array_map(
            fn(string $segment): string => $this->slugTranslator->toCanonical($segment, $language),
            $segments
        );

        $match = $this->router->matchAlias($canonical, $isDefault ? null : $segments);
        if ($match === null) {
            return null;
        }

        $slugs = array_values(array_slice($segments, $match['length']));

        return [
            'navigation' => $match['navigation'],
            'slugs'      => $slugs,
            'canonical'  => [...$this->localizeAliasPath($match['path'], $this->i18n->getDefaultLanguage()), ...$slugs],
            'localized'  => [...$this->localizeAliasPath($match['path'], $language), ...$slugs],
        ];
    }

    /**
     * Outbound: the form of a canonical (default-language) path in `$language`,
     * WITHOUT the language prefix. An alias path is localized, its remainder and
     * every non-alias path are returned unchanged.
     *
     * @param list<string> $segments canonical path segments
     * @return list<string>
     */
    public function toLocalized(array $segments, string $language): array
    {
        if ($segments === [] || $language === $this->i18n->getDefaultLanguage()) {
            return $segments;
        }

        $match = $this->router->matchAlias($segments);
        if ($match === null) {
            return $segments;
        }

        return [
            ...$this->localizeAliasPath($match['path'], $language),
            ...array_slice($segments, $match['length']),
        ];
    }

    /** @return list<string> the alias path, segment by segment, in `$language` */
    private function localizeAliasPath(string $path, string $language): array
    {
        $segments = array_values(array_filter(explode('/', $path), fn(string $s): bool => $s !== ''));
        if ($language === $this->i18n->getDefaultLanguage()) {
            return $segments;
        }

        return array_map(
            fn(string $segment): string => $this->slugTranslator->toLocalized($segment, $language),
            $segments
        );
    }
}
