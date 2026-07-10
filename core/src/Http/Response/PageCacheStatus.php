<?php

namespace Z77\Core\Http\Response;

/**
 * PageCacheStatus
 *
 * Diagnostic marker for how the current response relates to the server-side
 * PageCache. Emitted as the `X-Z77-PageCache` response header so developers
 * can distinguish a true cache hit from a freshly rendered page in the browser
 * dev tools — the HTTP cache headers (Cache-Control, ETag, Last-Modified) are
 * identical in both cases.
 *
 * Not used for 304 responses; the status code itself signals "client cache
 * still valid".
 */
enum PageCacheStatus: string
{
    /** Body loaded from PageCache file. */
    case Hit = 'HIT';

    /** Cache miss — rendered fresh and stored. */
    case Miss = 'MISS';

    /** PageCache skipped (DEBUG, POST, query string, fetch mode, module policy). */
    case Bypass = 'BYPASS';
}
