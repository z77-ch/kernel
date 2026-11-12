<?php

namespace Z77\Shared\Auth;

/**
 * Resolves the API device cookie to a device principal — the second credential
 * of the gateway (ADR-057), beside the bearer key ({@see TenantKeyResolverInterface}).
 * Declared via the `apiDeviceResolver` module config key (FQCN, at most one per
 * installation; none = the device channel is off and a cookie is ignored).
 * module-api ships the implementation (`Z77\Module\Api\Services\ApiDevices`).
 *
 * Contract for implementations:
 *   - the server stores only SHA-256 of the secret, compared with hash_equals()
 *   - an unknown, revoked or expired device, one whose app user is inactive or
 *     whose linked person is gone (ADR-058), returns null — the guard answers
 *     401 without distinguishing (no oracle)
 *   - the returned principal is built with {@see ApiPrincipal::device()}
 */
interface DeviceKeyResolverInterface
{
    /**
     * @param string $cookieValue the device cookie as the browser sent it
     * @return ApiPrincipal|null the device principal, or null when no live device matches
     */
    public function resolve(string $cookieValue): ?ApiPrincipal;
}
