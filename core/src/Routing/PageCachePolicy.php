<?php

namespace Z77\Core\Routing;

use Z77\Core\Config\AuthRole;
use Z77\Core\Http\Request;
use Z77\Core\Http\RequestMode;
use Z77\Core\Http\Response\Etag;
use Z77\Core\Libraries\Cache\PageCache;
use Z77\Core\Libraries\Cache\PageIdentity;
use Z77\Core\Services\ModuleManager;
use Z77\Shared\Services\AuthService;

/**
 * PageCachePolicy
 *
 * Single source of truth for the page-cache decision. Returns one of three modes:
 *   - NewPage             — render fresh, do not cache (debug, editor/admin session,
 *                           POST, query string, fetch mode, or module config
 *                           disabled)
 *   - PageFromCache       — server has a fresh entry, send it with ETag
 *   - PageFromClientCache — browser already has the fresh version (matched
 *                           If-None-Match against the cache file's mtime),
 *                           dispatcher will reply 304 with no body
 *
 * Variant A: this policy makes the full decision, including whether the
 * browser's local copy is still valid. The dispatcher does not need to read
 * request headers or compare ETags itself.
 */
class PageCachePolicy
{
    public function __construct(
        private ModuleManager $moduleManager,
        private PageCache $pageCache,
        private AuthService $authService,
        private bool $debug
    ) {}

    public function decide(Request $request): PageCacheDecision
    {
        if ($this->debug) {
            return PageCacheDecision::newPage();
        }

        // A role >= EDITOR session renders session-only chrome into the page:
        // the frontend editing buttons for editors (ADR-045), plus the admin
        // overlay and dev tools for admins. The PageIdentity has no user
        // dimension, so such a render must never enter the shared cache — and
        // an editor/admin must never be served the cached guest version
        // (CACHE-ADMIN-001, widened to EDITOR by ADR-045). Requires the session
        // to be started before this runs (AccessGuard::enforce() precedes
        // decide() in the Dispatcher).
        if ($this->authService->getCurrentUser()->hasAtLeast(AuthRole::EDITOR)) {
            return PageCacheDecision::newPage();
        }

        // GET and HEAD share the cache — HEAD is "GET without body".
        if (!$request->isReadMethod()) {
            return PageCacheDecision::newPage();
        }

        if ($request->hasQueryString()) {
            return PageCacheDecision::newPage();
        }

        // A content preview renders variant text that visitors must never get —
        // stated on its own, not left to the query-string rule above.
        if (\Z77\Shared\Content\ContentPreview::key() !== null) {
            return PageCacheDecision::newPage();
        }

        // Content slugs (remainder behind a slug-accepting alias or a reserved route)
        // select WHICH content the action renders, but the PageIdentity has no slug
        // dimension: `/referenzen/a` and `/referenzen/b` would share one cache entry.
        // Until the identity is keyed by URL (ADR-015 D2, deferred) such a page is
        // never cached.
        if ($request->getSlugs() !== []) {
            return PageCacheDecision::newPage();
        }

        if ($request->getMode() === RequestMode::Fetch) {
            return PageCacheDecision::newPage();
        }

        $module     = $request->getModule();
        $group      = $request->getGroup();
        $controller = $request->getController();
        $action     = $request->getAction();

        $policy = $this->moduleManager->getCachePolicy($module, $controller, $action);
        if (!$policy['enabled'] || $policy['ttl'] <= 0) {
            return PageCacheDecision::newPage();
        }

        $identity = new PageIdentity(
            language:   $request->getLanguage(),
            module:     $module,
            group:      $group,
            controller: $controller,
            action:     $action,
        );

        // Browser has fresh copy?
        $serverMtime = $this->pageCache->getMtime($identity);
        if ($serverMtime !== null
            && Etag::matches($request->getIfNoneMatch(), $serverMtime)
        ) {
            return PageCacheDecision::fromClientCache($identity, $policy['ttl'], $serverMtime);
        }

        return PageCacheDecision::fromCache($identity, $policy['ttl']);
    }
}
