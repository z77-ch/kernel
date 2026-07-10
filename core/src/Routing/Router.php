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
     * Longest-prefix NavigationAlias match. Returns the matched navigation entry
     * plus the captured trailing content slugs, or null when no alias prefixes
     * the path (ADR-015). The resolver matches the alias (navigationId + slugs);
     * resolving the id to its Navigation is done here, where the navigation cache
     * lives. A dangling alias (id resolves to no entry) yields null.
     *
     * @param list<string> $segments canonical path segments
     * @return array{navigation: Navigation, slugs: list<string>}|null
     */
    public function matchAlias(array $segments): ?array
    {
        $match = $this->urlResolver->matchAlias($segments);
        if ($match === null) return null;

        $nav = $this->navigationService->findById($match['navigationId']);
        if ($nav === null) return null;

        return ['navigation' => $nav, 'slugs' => $match['slugs']];
    }
}
