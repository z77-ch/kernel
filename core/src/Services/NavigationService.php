<?php

namespace Z77\Core\Services;

use Z77\Core\Libraries\CacheManager;
use Z77\Shared\Entities\MetaData;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Repositories\MetaDataRepository;
use Z77\Shared\Repositories\NavigationRepository;
use Z77\Shared\Tree\TreeService;

class NavigationService
{
    private ?Navigation $current   = null;
    private ?Navigation $uiCurrent = null;

    /** Tree algorithms for the navigation forest (roots grouped by render-slot slug). */
    private TreeService $navTree;

    public function __construct(
        private NavigationRepository  $navRepo,
        private MetaDataRepository    $metaRepo,
        private ModuleManager         $moduleManager,
        private NavigationUrlResolver $urlResolver,
        private CacheManager          $cacheManager
    ) {
        $this->navTree = new TreeService(fn(Navigation $n) => $n->getSlot());
    }

    private function getAll(): array
    {
        $cached = $this->cacheManager->data()->get('NavigationService', ['all']);
        if ($cached !== null) return $cached;
        $result = $this->navRepo->findAll();
        $this->cacheManager->data()->set('NavigationService', ['all'], $result, cachePersist: true);
        return $result;
    }

    /**
     * The public (canonical, default-language) URL of a navigation entry —
     * delegates to {@see NavigationUrlResolver::urlFor()}. Kept here as the
     * convenience seam templates already use ($navigationService is in their
     * context); the alias logic itself lives in the resolver (ADR-015).
     */
    public function urlFor(Navigation $entry): string
    {
        return $this->urlResolver->urlFor($entry);
    }

    /**
     * Href for a ref entry: the target's public URL plus the `?via=<refId>` UI-cursor param,
     * joined with `?` or `&` depending on whether the target URL already carries a query — a
     * target may now carry a UI-state {@see Navigation::$param}, so a naive `. '?via='` could
     * emit a double `?`. Templates rendering ref hrefs MUST use this, never manual concatenation.
     */
    public function urlForVia(Navigation $target, int $refId): string
    {
        $url = $this->urlFor($target);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'via=' . $refId;
    }

    public function getCurrent(): ?Navigation
    {
        return $this->current;
    }

    /**
     * Returns the entry that drives UI state (active section, sidebar highlight).
     * When a `?via=<refId>` query param resolved a ref entry, that ref is the UI
     * cursor. Otherwise falls back to the routing target ($current).
     */
    public function getUiCurrent(): ?Navigation
    {
        return $this->uiCurrent ?? $this->current;
    }

    public function isActive(Navigation $entry): bool
    {
        $ui = $this->getUiCurrent();
        return $ui !== null && $ui->getId() === $entry->getId();
    }

    public function findById(int $id): ?Navigation
    {
        foreach ($this->getAll() as $entry) {
            if ($entry->getId() === $id) return $entry;
        }
        return null;
    }

    /**
     * Sets $uiCurrent from a ref id (typically the `?via=` query param). The ref
     * must exist, must actually be a ref entry, and its target must equal the
     * current routing entry — otherwise the hint is silently ignored.
     */
    public function resolveUiCurrent(?int $refId): void
    {
        $this->uiCurrent = null;
        if ($refId === null || $refId <= 0 || $this->current === null) return;

        $refEntry = $this->findById($refId);
        if ($refEntry === null) return;
        if ($refEntry->getRef() !== $this->current->getId()) return;

        $this->uiCurrent = $refEntry;
    }

    /**
     * Resolves the routing target ($current) for the matched 4-tuple. Since Phase 4
     * (ADR-015) entries carry no `params` — matching is the bare 4-tuple, first hit.
     *
     * Known limitation: when multiple entries share one 4-tuple (allowed since D1,
     * disambiguated by their alias path) this picks the first. No seed data has a
     * shared 4-tuple; the proper fix carries the matched navigationId from the alias
     * match into resolveCurrent — deferred until such data exists.
     *
     * @param array<string, mixed> $query unused since Phase 4; kept for the caller signature
     */
    public function resolveCurrent(string $module, string $group, string $controller, string $action, array $query = []): void
    {
        $this->current = null;
        foreach ($this->getAll() as $entry) {
            if ($entry->getModule() === $module
                && $entry->getGroup() === $group
                && $entry->getController() === $controller
                && $entry->getAction() === $action
            ) {
                $this->current = $entry;
                return;
            }
        }
    }

    /**
     * Looks up a routable entry by its canonical 4-tuple path. Friendly URLs and
     * params are gone (Phase 4, ADR-015) — the public URL layer is NavigationAlias
     * (matched earlier, with precedence). This is the static-navigation fallback
     * for a direct canonical hit (e.g. `/frontend/main/index/home`).
     *
     * @param array<string, mixed> $query unused since Phase 4; kept for the caller signature
     */
    public function findByPath(string $path, array $query = []): ?Navigation
    {
        foreach ($this->getAll() as $entry) {
            if ($entry->getRef() !== null) continue; // refs are UI-only, never routing targets
            if ($entry->getCanonicalPath() === $path) return $entry;
        }
        return null;
    }

    /**
     * Tree-roots attached to a render-slot, by its slug. Validates the slug against
     * the config slot registry (ModuleManager, ADR-022) and THROWS on an unknown slot
     * — a typo or a removed slot fails loudly instead of silently returning []. Inactive
     * entries are omitted (this is the UI-facing lookup; the backend list view reads the
     * repository directly to see everything).
     *
     * @return Navigation[]
     * @throws UnknownNavigationSlotException when $slot is not a registered slot
     */
    public function getBySlot(string $slot): array
    {
        if (!$this->moduleManager->isKnownSlot($slot)) {
            throw new UnknownNavigationSlotException($slot);
        }
        // Tree-roots whose slot === $slot (a non-empty slot implies parentId === null
        // by the slot-XOR-parent invariant), active-filtered, ordered by sortKey.
        return $this->navTree->children(
            array_filter($this->getAll(), fn(Navigation $e) => $e->isActive()),
            null,
            $slot
        );
    }

    /**
     * Iterates level-1 sections for a slot, yielding each with its resolved children and active flag.
     * Inactive sections + inactive descendants are skipped entirely.
     *
     * @return \Generator<int, array{section: Navigation, children: Navigation[], active: bool}>
     */
    public function iterateSections(string $slot): \Generator
    {
        $cursorId = $this->getUiCurrent()?->getId();
        foreach ($this->getBySlot($slot) as $section) {
            $children = $this->getActiveChildren($section);
            $isActive = false;
            if ($cursorId !== null) {
                foreach ($this->iterateTree($section) as $node) {
                    if ($node['entry']->getId() === $cursorId) {
                        $isActive = true;
                        break;
                    }
                }
            }
            yield ['section' => $section, 'children' => $children, 'active' => $isActive];
        }
    }

    public function getActiveSectionBySlot(string $slot): ?Navigation
    {
        $cursor = $this->getUiCurrent();
        if ($cursor === null) return null;
        $cursorId = $cursor->getId();
        foreach ($this->getBySlot($slot) as $section) {
            foreach ($this->iterateTree($section) as $node) {
                if ($node['entry']->getId() === $cursorId) {
                    return $section;
                }
            }
        }
        return null;
    }

    /**
     * Iterates all descendants of a root entry depth-first, yielding each with depth and active flag.
     * Inactive entries (and their entire subtree) are skipped.
     *
     * @return \Generator<int, array{entry: Navigation, depth: int, active: bool}>
     */
    public function iterateTree(Navigation $root): \Generator
    {
        yield from $this->iterateSubTree($root, 0);
    }

    private function iterateSubTree(Navigation $entry, int $depth): \Generator
    {
        foreach ($this->getActiveChildren($entry) as $child) {
            $isActive = $this->current !== null && $this->current->getId() === $child->getId();
            yield ['entry' => $child, 'depth' => $depth, 'active' => $isActive];
            yield from $this->iterateSubTree($child, $depth + 1);
        }
    }

    /**
     * Resolves the first descendant that produces a navigable link — either a
     * regular entry with a non-empty URL, or a ref entry (caller must rewrite
     * the href to target URL + `?via=<refId>`). Skips inactive descendants.
     */
    public function resolveFirstNavigable(Navigation $entry): ?Navigation
    {
        foreach ($this->iterateTree($entry) as $node) {
            $child = $node['entry'];
            if ($child->getRef() !== null) return $child;
            if ($child->getUrl() !== '')   return $child;
        }
        return null;
    }

    /**
     * Returns the entry's children with inactive ones filtered out. Used by
     * UI-facing iterators. The backend list view should call getChildren()
     * instead to see the full tree (including inactive).
     *
     * @return Navigation[]
     */
    private function getActiveChildren(Navigation $entry): array
    {
        return array_values(array_filter(
            $this->getChildren($entry),
            fn(Navigation $c) => $c->isActive()
        ));
    }

    public function getChildren(Navigation $entry): array
    {
        $parentId = $entry->getId();
        if ($parentId === null) return [];
        return $this->navTree->children($this->getAll(), $parentId);
    }

    // ── View areas (environments) ───────────────────────────────────────────
    // A view area is a module that declares `viewArea: true` in its config
    // (ModuleManager, ADR-022). Its render-slots + labels are config (`navSlots`),
    // not entities. The invariant (view-area name === module key) makes the current
    // view area the module of the current routing entry.

    /**
     * View areas for a switcher: every view-area module (ModuleManager) that has at
     * least one reachable navigable entry (a module with no reachable page would be a
     * dead switch and is skipped). Ordered by module registration.
     *
     * Note: visibility here is reachability-based, not role-based. The backend topbar
     * (sole consumer today) is already auth-gated, so per-role gating is deferred.
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    public function getViewAreas(): array
    {
        $currentName = $this->getCurrentViewAreaName();
        $areas = [];
        foreach ($this->moduleManager->getViewAreaKeys() as $moduleKey) {
            $url = $this->resolveViewAreaUrl($moduleKey);
            if ($url === '') continue;
            $areas[] = [
                'key'    => $moduleKey,
                'label'  => $this->moduleManager->getViewAreaLabel($moduleKey),
                'url'    => $url,
                'active' => $moduleKey === $currentName,
            ];
        }
        return $areas;
    }

    /**
     * Name of the view area the current request belongs to. By the view-area
     * invariant (name === bound module key) this is the module of the current
     * routing/UI entry. Null on convention routes without an entry.
     */
    public function getCurrentViewAreaName(): ?string
    {
        $module = $this->getUiCurrent()?->getModule() ?? '';
        return $module === '' ? null : $module;
    }

    /**
     * Entry URL of a view-area module: the first navigable entry found by scanning
     * its render-slots (config order), then the tree-roots within each slot. Ref
     * entries resolve to target URL + `?via=<refId>`. Empty string = none.
     */
    private function resolveViewAreaUrl(string $moduleKey): string
    {
        foreach (array_keys($this->moduleManager->getNavSlots($moduleKey)) as $slot) {
            foreach ($this->getBySlot($slot) as $root) {
                $nav = $this->firstNavigableInclusive($root);
                if ($nav === null) continue;
                if ($nav->getRef() !== null) {
                    $target = $this->findById($nav->getRef());
                    if ($target !== null) return $this->urlForVia($target, $nav->getId());
                    continue;
                }
                $url = $this->urlFor($nav);
                if ($url !== '') return $url;
            }
        }
        return '';
    }

    /**
     * Like resolveFirstNavigable but considers the entry itself first — a flat
     * tree-root (e.g. a frontend page) is navigable on its own, with no children.
     */
    private function firstNavigableInclusive(Navigation $root): ?Navigation
    {
        if ($root->getRef() !== null || $root->getUrl() !== '') return $root;
        return $this->resolveFirstNavigable($root);
    }

    public function findMetaData(int $navigationId, string $language): ?MetaData
    {
        $cached = $this->cacheManager->data()->get('NavigationService', ['meta', $navigationId, $language]);
        if ($cached !== null) return $cached;
        $result = $this->metaRepo->findByNavigationAndLanguage($navigationId, $language);
        if ($result !== null) {
            $this->cacheManager->data()->set('NavigationService', ['meta', $navigationId, $language], $result, cachePersist: true);
        }
        return $result;
    }
}
