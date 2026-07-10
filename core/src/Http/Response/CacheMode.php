<?php

namespace Z77\Core\Http\Response;

/**
 * CacheMode
 *
 * Domain-level cache directive for an HTTP response. The Dispatcher selects a
 * mode based on the page-cache decision; the response maps it to the concrete
 * Cache-Control header value when sending. Keeping this as an enum (instead of
 * raw strings) means the Dispatcher does not need to know HTTP cache syntax,
 * and typos cannot creep in.
 *
 * Strategy A (server has cache authority): the server-side PageCache decides
 * what is fresh. Browsers must revalidate every request; they may store the
 * payload locally but cannot serve it without asking the server. Editor-driven
 * invalidation therefore takes effect on the next request.
 */
enum CacheMode
{
    /** 200 + body. Server has authority — browser must revalidate every request. */
    case ServerCached;

    /** 304 without body. Browser's stored copy was confirmed fresh via ETag. */
    case NotModified;

    /** 200 + body. Never cache anywhere (POST, query strings, debug, dynamic pages). */
    case NoStore;
}
