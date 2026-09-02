<?php

namespace Z77\Shared\Api;

/**
 * An API service answers one endpoint of the stateless API (api-envelope-v1).
 * The framework's api module owns transport (routing, key auth, rate limit,
 * status codes, headers); a service owns exactly the payload.
 *
 * Implementations are PROJECT code (declared in the project's override
 * config), never framework code — the framework ships the mechanism, the
 * project the data (CE principle). A service:
 *
 *   - never touches header(), echo, or the session (stateless path — the
 *     session services are not even registered, resolving them throws)
 *   - reports failure through {@see ApiResult::error()}, never by output
 *   - returns the payload with its content hash as ETag; the responder turns
 *     a matching If-None-Match into a 304 — the service does not compare
 */
interface ApiServiceInterface
{
    public function handle(ApiRequest $request): ApiResult;
}
