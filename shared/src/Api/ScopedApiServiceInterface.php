<?php

namespace Z77\Shared\Api;

/**
 * An API service that names, per method, the scope a DEVICE needs (ADR-057).
 * Implementing it is what opens a service to the device channel and to POST:
 *
 *   - a plain {@see ApiServiceInterface} answers GET/HEAD only and is closed to
 *     devices (403) — the machine channel's rules, unchanged;
 *   - a scoped service answers exactly the methods for which scopeFor() names
 *     a scope; any other method is 405. A device must hold the named scope
 *     (403 otherwise); {@see NO_SCOPE} lets any paired device in.
 *
 * Machine (bearer) principals keep today's rule: past the guard, the service
 * decides by tenant. A service that writes on behalf of a person checks
 * `$request->device()` itself and refuses a machine caller.
 *
 * HEAD is asked as `get`. The answer must not depend on the request body —
 * it is called before the service handles anything.
 */
interface ScopedApiServiceInterface extends ApiServiceInterface
{
    /** Offered to every paired device, no particular scope required. */
    public const NO_SCOPE = '';

    /**
     * @param string       $method lower-case: `get` or `post`
     * @param list<string> $slugs  path segments after the endpoint (`/api/v1/devices/me` → `['me']`)
     * @return string|null the scope a device needs, {@see NO_SCOPE}, or null = method not offered (405)
     */
    public function scopeFor(string $method, array $slugs): ?string;
}
