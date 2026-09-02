<?php

namespace Z77\Shared\Api;

/**
 * Everything an {@see ApiServiceInterface} may know about the request —
 * deliberately NOT the framework Request: a service sees the authenticated
 * tenant and its parameters, nothing transport-level.
 */
final class ApiRequest
{
    /**
     * @param string            $tenantId    tenant resolved by the ApiKeyGuard
     * @param list<string>      $slugs       path segments after the endpoint (e.g. a dataset name)
     * @param array<string,mixed> $params    query parameters, passed through untouched (e.g. lang)
     * @param string|null       $ifNoneMatch raw If-None-Match header value, if the client sent one
     * @param string|null       $keyRef      WHICH of the tenant's connections called (opaque,
     *                                       project-assigned — see ApiPrincipal); null when the
     *                                       project resolves tenants only
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly array $slugs = [],
        public readonly array $params = [],
        public readonly ?string $ifNoneMatch = null,
        public readonly ?string $keyRef = null,
    ) {
    }
}
