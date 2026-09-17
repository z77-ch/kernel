<?php

namespace Z77\Core\Routing;

use Z77\Core\Services\NavigationService;
use Z77\Core\Services\NavigationUrlResolver;
use Z77\Shared\Entities\Navigation;

class Router
{
    public function __construct(
        private NavigationService     $navigationService,
        private NavigationUrlResolver $urlResolver
    ) {}

    /**
     * @param array<string, mixed> $query request query parameters ($_GET)
     */
    public function match(string $path, array $query = []): ?Navigation
    {
        return $this->navigationService->findByPath($path, $query);
    }

    /**
     * NavigationAlias match — exact, or as a prefix for a slug-accepting alias
     * (rule and the `$alternative` spelling: {@see NavigationUrlResolver::matchAlias()}).
     * The resolver matches the alias; resolving the id to its Navigation is done
     * here, where the navigation cache lives. A dangling alias (id resolves to no
     * entry) yields null — for the inbound AND the outbound side alike, which is what
     * keeps an emitted URL resolvable ({@see AliasPathResolver}).
     *
     * @param list<string>      $segments    canonical path segments
     * @param list<string>|null $alternative same request, other spelling (optional)
     * @return array{navigation: Navigation, path: string, length: int}|null
     */
    public function matchAlias(array $segments, ?array $alternative = null): ?array
    {
        $match = $this->urlResolver->matchAlias($segments, $alternative);
        if ($match === null) return null;

        $nav = $this->navigationService->findById($match['navigationId']);
        if ($nav === null) return null;

        return ['navigation' => $nav, 'path' => $match['path'], 'length' => $match['length']];
    }
}
