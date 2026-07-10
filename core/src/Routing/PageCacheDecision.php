<?php

namespace Z77\Core\Routing;

use Z77\Core\Libraries\Cache\PageIdentity;

/**
 * PageCacheDecision
 *
 * The PageCachePolicy's verdict for a single request. Three shapes, each with
 * exactly the fields it needs — built through named factories so unsound
 * combinations (e.g. PageFromCache without identity, or PageFromClientCache
 * without etag) are unreachable by construction.
 */
class PageCacheDecision
{
    private function __construct(
        public readonly PageCachePolicyMode $mode,
        public readonly ?PageIdentity $identity = null,
        public readonly ?int $ttl = null,
        public readonly ?int $etag = null,
    ) {}

    /** Render fresh, do not cache. */
    public static function newPage(): self
    {
        return new self(PageCachePolicyMode::NewPage);
    }

    /** Server has a fresh entry; load body from disk and send with ETag. */
    public static function fromCache(PageIdentity $identity, int $ttl): self
    {
        return new self(
            mode:     PageCachePolicyMode::PageFromCache,
            identity: $identity,
            ttl:      $ttl,
        );
    }

    /** Browser already has the fresh version; reply 304 with ETag, no body. */
    public static function fromClientCache(PageIdentity $identity, int $ttl, int $etag): self
    {
        return new self(
            mode:     PageCachePolicyMode::PageFromClientCache,
            identity: $identity,
            ttl:      $ttl,
            etag:     $etag,
        );
    }
}
