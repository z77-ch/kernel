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
 *   - NewPage             — render fresh, do not cache (debug, admin session,
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

        // A role >= ADMIN session renders admin-only chrome (frontend admin
        // overlay, dev tools) into the page. The PageIdentity has no user
        // dimension, so an admin's render must never enter the shared cache —
        // and an admin must never be served the cached guest version
        // (CACHE-ADMIN-001). Requires the session to be started before this
        // runs (AccessGuard::enforce() precedes decide() in the Dispatcher).
        if ($this->authService->getCurrentUser()->hasAtLeast(AuthRole::ADMIN)) {
            return PageCacheDecision::newPage();
        }

        // GET and HEAD share the cache — HEAD is "GET without body".
        if (!$request->isReadMethod()) {
            return PageCacheDecision::newPage();
        }

        if ($request->hasQueryString()) {
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
