<?php

namespace Z77\Core\Services;

use Z77\Core\Libraries\CacheManager;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Entities\NavigationAlias;
use Z77\Shared\Repositories\NavigationAliasRepository;

/**
 * Owns the NavigationAlias layer (ADR-015): the alias cache plus inbound
 * (longest-prefix matching) and outbound (public URL building) resolution.
 *
 * Extracted from NavigationService so the navigation-structure service is not
 * overloaded with URL concerns. Deliberately alias-only — it never reads the
 * navigation tree, so it carries no dependency on NavigationService (no cycle).
 * Resolving a matched alias to its Navigation entity is the caller's job
 * ({@see \Z77\Core\Routing\Router::matchAlias}), which owns the navigation cache.
 */
class NavigationUrlResolver
{
    public function __construct(
        private NavigationAliasRepository $aliasRepo,
        private CacheManager              $cacheManager
    ) {}

    /** All NavigationAlias rows, cached as a single APCu entry (see ADR-015). */
    private function getAllAliases(): array
    {
        $cached = $this->cacheManager->data()->get('NavigationUrlResolver', ['aliases-all']);
        if ($cached !== null) return $cached;
        $result = $this->aliasRepo->findAll();
        $this->cacheManager->data()->set('NavigationUrlResolver', ['aliases-all'], $result, cachePersist: true);
        return $result;
    }

    /** The canonical (public) entry alias of a navigation, or null when none exists. */
    public function getCanonicalAlias(int $navigationId): ?NavigationAlias
    {
        foreach ($this->getAllAliases() as $alias) {
            if ($alias->getNavigationId() === $navigationId
                && $alias->isCanonical()
                && $alias->isActive()
            ) {
                return $alias;
            }
        }
        return null;
    }

    /** The active alias whose path matches exactly, or null. Exact match only (no prefix logic). */
    public function findByAliasPath(string $path): ?NavigationAlias
    {
        foreach ($this->getAllAliases() as $alias) {
            if ($alias->getPath() === $path && $alias->isActive()) {
                return $alias;
            }
        }
        return null;
    }

    /**
     * The public (canonical, default-language) URL of a navigation entry — the
     * single source of truth for outbound links (ADR-015). Returns the entry's
     * canonical {@see NavigationAlias} path when one exists, else falls back to
     * the entry's own 4-tuple path (e.g. backend convention routes with no alias).
     * Empty string for a non-routable container. Callers localize via
     * `localizedUrl()` and append `?via=` for ref pointers themselves.
     */
    public function urlFor(Navigation $entry): string
    {
        $id  = $entry->getId();
        $url = $entry->getUrl();
        if ($id !== null) {
            $alias = $this->getCanonicalAlias($id);
            if ($alias !== null) $url = $alias->getPath();
        }
        return $this->appendParam($url, $entry->getParam());
    }

    /**
     * Appends the entry's UI-state query fragment ({@see Navigation::$param}, e.g. `key=front`)
     * to its generated href — with `?` or `&` depending on whether the URL already carries a
     * query. A container entry (empty url) or an empty param is returned unchanged. This is the
     * single URL-generation seam, so every menu href picks up the param; routing
     * (`findByPath`/`resolveCurrent`) never sees it (it matches the 4-tuple), keeping it
     * UI-state, not routing (the ADR-015 line).
     */
    private function appendParam(string $url, string $param): string
    {
        $param = ltrim($param, '?&');
        if ($param === '' || $url === '') {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . $param;
    }

    /**
     * Alias match (ADR-015, amended 2026-09-17). An alias matches its EXACT path;
     * only an alias with `acceptsSlugs` also matches as a prefix. Tried longest
     * first: the full path, then each shorter prefix. A shorter prefix can only be
     * a slug-accepting alias — an exact-only alias found there is skipped, so
     * `/kontakt/anything` is a miss, while `/referenzen/archiv/x` still reaches a
     * slug-accepting `/referenzen` past an exact-only `/referenzen/archiv`.
     *
     * `$alternative` is a second spelling of the SAME request (the raw segments
     * beside the translated ones, same length): per length the primary spelling is
     * tried first, then the alternative. That keeps an alias reachable whose own
     * path happens to be a localized word (alias `/contact`, table
     * `kontakt → contact`) — without it the emitted URL would not resolve.
     *
     * Returns the matched navigationId — the caller resolves it to a Navigation
     * (the navigation cache lives in NavigationService, not here). `length` is the
     * number of segments the alias path covers; the caller takes the remainder
     * (the content slugs) from the spelling it wants — always the raw one.
     *
     * @param list<string>      $segments    canonical path segments
     * @param list<string>|null $alternative same request, other spelling (optional)
     * @return array{navigationId: int, path: string, length: int}|null
     */
    public function matchAlias(array $segments, ?array $alternative = null): ?array
    {
        $total = count($segments);
        if ($alternative !== null && ($alternative === $segments || count($alternative) !== $total)) {
            $alternative = null;
        }

        for ($i = $total; $i >= 1; $i--) {
            foreach ([$segments, $alternative] as $spelling) {
                if ($spelling === null) continue;

                $alias = $this->findByAliasPath('/' . implode('/', array_slice($spelling, 0, $i)));
                if ($alias === null) continue;
                if ($i < $total && !$alias->acceptsSlugs()) continue;

                return [
                    'navigationId' => $alias->getNavigationId(),
                    'path'         => $alias->getPath(),
                    'length'       => $i,
                ];
            }
        }
        return null;
    }
}
