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
     * Longest-prefix alias match (ADR-015 inbound flow). Tries the full canonical
     * path, then each shorter prefix, against the alias table; the first (longest)
     * hit wins. Segments beyond the matched alias are returned as content slugs.
     *
     * Returns the matched navigationId — the caller resolves it to a Navigation
     * (the navigation cache lives in NavigationService, not here).
     *
     * @param list<string> $segments canonical path segments (language stripped + translated)
     * @return array{navigationId: int, slugs: list<string>}|null
     */
    public function matchAlias(array $segments): ?array
    {
        for ($i = count($segments); $i >= 1; $i--) {
            $candidate = '/' . implode('/', array_slice($segments, 0, $i));
            $alias = $this->findByAliasPath($candidate);
            if ($alias === null) continue;

            return [
                'navigationId' => $alias->getNavigationId(),
                'slugs'        => array_values(array_slice($segments, $i)),
            ];
        }
        return null;
    }
}
