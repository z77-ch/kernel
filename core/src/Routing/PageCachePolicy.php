<?php

namespace Z77\Core\Routing;

use Z77\Core\Http\Request;
use Z77\Core\Http\RequestMode;
use Z77\Core\Libraries\Cache\PageCache;
use Z77\Core\Libraries\Cache\PageIdentity;
use Z77\Core\Services\ModuleManager;

/**
 * PageCachePolicy
 *
 * Single source of truth for the page-cache decision. Returns one of three modes:
 *   - NewPage             — render fresh, do not cache (debug, POST, query string,
 *                           fetch mode, or module config disabled)
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
        private bool $debug
    ) {}

    public function decide(Request $request): PageCacheDecision
    {
        if ($this->debug) {
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
            && $this->ifNoneMatchHits($request->getIfNoneMatch(), $serverMtime)
        ) {
            return PageCacheDecision::fromClientCache($identity, $policy['ttl'], $serverMtime);
        }

        return PageCacheDecision::fromCache($identity, $policy['ttl']);
    }

    /**
     * Returns true if the client's If-None-Match header matches the server's
     * current ETag for this resource.
     *
     * RFC 7232 allows a comma-separated list and the wildcard "*" (matches any
     * existing resource). Each tag may be strong ("v") or weak (W/"v"); for our
     * numeric mtime-based tags both forms are equivalent.
     */
    private function ifNoneMatchHits(?string $raw, int $serverMtime): bool
    {
        if ($raw === null) {
            return false;
        }
        $raw = trim($raw);
        if ($raw === '*') {
            return true;
        }

        foreach (explode(',', $raw) as $part) {
            $tag = trim($part);
            if (str_starts_with($tag, 'W/')) {
                $tag = substr($tag, 2);
            }
            $tag = trim($tag, '"');
            if (ctype_digit($tag) && (int) $tag === $serverMtime) {
                return true;
            }
        }
        return false;
    }
}
