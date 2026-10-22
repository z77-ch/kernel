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
    /** Session slot of the ref cursor memory: target id => ref id (NAV-VIA-001). */
    public const VIA_SESSION_KEY = 'nav.via';

    private ?Navigation $current   = null;
    private ?Navigation $uiCurrent = null;

    /**
     * Cursor for a page that has no navigation entry of its own — a sub-page of
     * a listed controller. Set by {@see resolveCurrent()} ONLY when the exact
     * 4-tuple matched nothing, so it can never override a real match.
     */
    private ?Navigation $cursorFallback = null;

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
     * Sets $uiCurrent from a ref id (typically the `?via=` query param) and the
     * session-sticky ref memory (NAV-VIA-001). The ref must exist, be active,
     * and target the screen's entry — `$current`, or on a sub-page the sibling
     * fallback — otherwise the hint is silently ignored. Rules in {@see refCursorIn()}.
     *
     * `$current` is never touched (canonical path, SEO).
     *
     * @param array<int, int> $memory target id => ref id, as stored under {@see VIA_SESSION_KEY}
     * @return array<int, int> the memory to store back
     */
    public function resolveUiCurrent(?int $refId, array $memory = []): array
    {
        // Starts on the sub-page fallback, not on null: `resolveCurrent()` sets
        // it only when nothing matched, so a page WITH its own entry is
        // unaffected (fallback stays null, cursor falls through to $current).
        $this->uiCurrent = $this->cursorFallback;

        [$ref, $memory] = self::refCursorIn(
            $this->current ?? $this->cursorFallback,
            $refId,
            $memory,
            fn(int $id): ?Navigation => $this->findById($id)
        );
        if ($ref !== null) {
            $this->uiCurrent = $ref;
        }
        return $memory;
    }

    /**
     * The ref cursor rule (NAV-VIA-001), pure so it can be tested without a
     * navigation store (tests/backend-access.php). `via` = the menu node one
     * came through; it holds until another menu path to the same screen is taken.
     *
     *   - `via` names an active ref to the target → that ref is the cursor, remembered
     *   - `via` equals the target's own id        → no ref cursor, memory for the target cleared
     *     (the regular menu link carries it, {@see isRefTarget()})
     *   - no (valid) `via`                         → the remembered ref, if it still
     *     exists, is active and targets the screen; a stale memory is dropped silently
     *
     * @param array<int, int>              $memory   target id => ref id
     * @param callable(int): ?Navigation   $findById
     * @return array{0: ?Navigation, 1: array<int, int>} the ref carrying the cursor (null = none), the updated memory
     */
    public static function refCursorIn(?Navigation $target, ?int $via, array $memory, callable $findById): array
    {
        $targetId = $target?->getId();
        if ($targetId === null || $target->getRef() !== null) {
            return [null, $memory];
        }

        if ($via === $targetId) {
            unset($memory[$targetId]);
            return [null, $memory];
        }

        $refTo = static fn(?Navigation $e): bool => $e !== null && $e->getRef() === $targetId && $e->isActive();

        if ($via !== null && $via > 0 && $refTo($ref = $findById($via))) {
            $memory[$targetId] = $via;
            return [$ref, $memory];
        }

        $remembered = $memory[$targetId] ?? null;
        if ($remembered === null) {
            return [null, $memory];
        }
        $ref = is_int($remembered) && $remembered > 0 ? $findById($remembered) : null;
        if ($refTo($ref)) {
            return [$ref, $memory];
        }
        unset($memory[$targetId]);
        return [null, $memory];
    }

    /**
     * True when at least one active ref points at this regular entry. Its own menu
     * link then carries `?via=<own id>` — the «forget» signal of {@see refCursorIn()},
     * so leaving through the regular entry ends the ref cursor (NAV-VIA-001).
     */
    public function isRefTarget(Navigation $entry): bool
    {
        $id = $entry->getId();
        if ($id === null || $entry->getRef() !== null) {
            return false;
        }
        foreach ($this->getAll() as $other) {
            if ($other->getRef() === $id && $other->isActive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * A page WITHOUT an entry of its own names where it belongs in the menu
     * (NAV-CURSOR-001). The sibling fallback in {@see resolveCurrent()} only
     * reaches pages whose controller is listed — as soon as a screen becomes a
     * TAB of some object, its controller has no listed entry at all, and the
     * left column would go empty (AXO3: `estate/tree` under the tenant tabs).
     *
     * Called from the controller, so it lands after resolveUiCurrent() and
     * before anything renders. Both cursor fields are set: `uiCurrent` is what
     * the subnav reads, `cursorFallback` what a later resolveUiCurrent() would
     * start from.
     *
     * ⚠️ `$current` is NOT touched — it drives the SEO metadata and the
     * canonical path. A page borrowing another entry's canonical URL would be
     * a lie told to search engines, not a menu highlight. Same reason the
     * sibling fallback keeps its hands off it.
     *
     * ⚠️ A page that HAS its own entry is left alone: its own position is
     * always the better one, and a stray call must not move it.
     */
    public function setUiCursor(string $canonicalPath): void
    {
        if ($this->current !== null) {
            return;
        }

        $entry = $this->findByPath($canonicalPath);
        // Inactive entries are skipped for the same reason the sibling fallback
        // skips them: `iterateTree()` never yields them, so a cursor sitting on
        // one would find no section either — and the column stays empty.
        if ($entry === null || !$entry->isActive()) {
            return;
        }

        $this->cursorFallback = $entry;
        $this->uiCurrent      = $entry;
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
        $this->current        = null;
        $this->cursorFallback = null;

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

        // No entry for this exact action — a SUB-PAGE of a listed controller
        // (backend: `estate/tree` beside the listed `estate/list`). Without a
        // cursor the whole submenu disappears: `getActiveSectionBySlot()` finds
        // no section, and subnav returns early. So the cursor falls back to a
        // sibling of the same controller — that is where such a page belongs,
        // and it is the entry the operator clicked to get here.
        //
        // ⚠️ The fallback lands on the UI CURSOR only, never on `$current`.
        // `$current` drives the SEO metadata and the canonical path
        // (AbstractBaseController); a sub-page borrowing its sibling's canonical
        // URL would be a lie told to search engines, not a menu highlight.
        //
        // Inactive entries are skipped: `iterateTree()` skips them too, so a
        // cursor sitting on one would find no section either.
        foreach ($this->getAll() as $entry) {
            if ($entry->getModule() === $module
                && $entry->getGroup() === $group
                && $entry->getController() === $controller
                && $entry->isActive()
            ) {
                $this->cursorFallback = $entry;
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
     *
     * $allows (optional) is asked for every navigable candidate; a candidate it
     * refuses is skipped. The backend menu passes its access check here
     * (ADR-045), so a section links to the first page the user may open.
     *
     * @param (callable(Navigation): bool)|null $allows
     */
    public function resolveFirstNavigable(Navigation $entry, ?callable $allows = null): ?Navigation
    {
        foreach ($this->iterateTree($entry) as $node) {
            $child = $node['entry'];
            if ($child->getRef() === null && $child->getUrl() === '') continue;
            if ($allows === null || $allows($child)) return $child;
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
     * May a user open the page this entry leads to? The access half of the menu
     * rule (ADR-045 §2), pure — the lookups come in as callables, so the backend
     * menu, the frontend admin overlay and tests/backend-access.php ask the same
     * question: a ref asks for its target entry (a ref to a ref is not followed —
     * the subnav does not either), an entry without a target leads nowhere.
     *
     * @param callable(int): ?Navigation                    $findById
     * @param callable(string, string, string, string): bool $reachable
     *        (module, group, controller, action) as the entry stores them —
     *        typically AuthService::canReach() for one user
     */
    public static function entryAllowedIn(Navigation $entry, callable $findById, callable $reachable): bool
    {
        if ($entry->getRef() !== null) {
            $target = $findById($entry->getRef());
            return $target !== null && $target->getRef() === null
                && self::entryAllowedIn($target, $findById, $reachable);
        }
        if ($entry->getModule() === '') {
            return false;
        }
        return $reachable($entry->getModule(), $entry->getGroup(), $entry->getController(), $entry->getAction());
    }

    /**
     * View areas for a switcher: every view-area module (ModuleManager) that has at
     * least one reachable navigable entry (a module with no reachable page would be a
     * dead switch and is skipped). Ordered by module registration.
     *
     * Visibility is reachability-based. Role-based only when the caller passes
     * $allows (asked for every navigable candidate, see resolveFirstNavigable()):
     * the backend menu and the frontend admin overlay do (ADR-045, both via
     * {@see entryAllowedIn()}), so an editor's «Backend» entry leads to a page
     * he may open.
     *
     * @param (callable(Navigation): bool)|null $allows
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    public function getViewAreas(?callable $allows = null): array
    {
        $currentName = $this->getCurrentViewAreaName();
        $areas = [];
        foreach ($this->moduleManager->getViewAreaKeys() as $moduleKey) {
            $url = $this->resolveViewAreaUrl($moduleKey, $allows);
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
    private function resolveViewAreaUrl(string $moduleKey, ?callable $allows): string
    {
        foreach (array_keys($this->moduleManager->getNavSlots($moduleKey)) as $slot) {
            foreach ($this->getBySlot($slot) as $root) {
                $nav = $this->firstNavigableInclusive($root, $allows);
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
    private function firstNavigableInclusive(Navigation $root, ?callable $allows): ?Navigation
    {
        if (($root->getRef() !== null || $root->getUrl() !== '') && ($allows === null || $allows($root))) {
            return $root;
        }
        return $this->resolveFirstNavigable($root, $allows);
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
