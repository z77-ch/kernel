<?php

namespace Z77\Shared\Auth;

/**
 * WHO called the API: the tenant, and optionally WHICH of the tenant's
 * connections (api-principal handoff, 2026-09-02). A tenant can hold several
 * connections, each delivering a different selection, each the unit of
 * revocation — the widget established the pattern before the API.
 *
 * `keyRef` is OPAQUE to the framework: the project assigns and interprets it,
 * the framework only passes it through (into ApiRequest, the throttle key,
 * and the api.log line). It MUST NOT be the key or its hash — it appears in
 * logs. A short stable slug of the connection (`[a-z0-9-]`) is the intended
 * shape. Projects resolving only a tenant return null and run unchanged.
 */
final class ApiPrincipal
{
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $keyRef = null,
    ) {
    }

    /** Log/throttle rendering: `8` or `8:widget-a`. */
    public function label(): string
    {
        return $this->keyRef === null ? $this->tenantId : $this->tenantId . ':' . $this->keyRef;
    }
}
