<?php
namespace Z77\Core\Services;

use Z77\Core\Http\Response\ResponseInterface;

/**
 * A request guard decides, after routing and before the action runs, whether
 * the request may proceed. The Dispatcher calls exactly one guard per request;
 * which one is wired in Bootstrap::pullUp() based on the matched route:
 *
 *   - stateful routes (default) → AccessGuard  (session, roles, CSRF)
 *   - stateless routes (`stateless` reserved routes, e.g. /api) → ApiKeyGuard
 *
 * Return null to allow the request, or a ready-to-send response (401 envelope,
 * login redirect, …) to deny it — the Dispatcher sends it and skips the action.
 */
interface RequestGuardInterface
{
    public function enforce(): ?ResponseInterface;
}
