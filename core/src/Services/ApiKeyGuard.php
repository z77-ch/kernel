<?php
namespace Z77\Core\Services;

use Z77\Core\DI,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Shared\Api\ApiLog,
    Z77\Shared\Auth\ApiPrincipal,
    Z77\Shared\Auth\TenantKeyResolverInterface,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * Guard for stateless API routes: bearer key → tenant, no session involved.
 *
 * Verification only — key storage, administration, and revocation are project
 * code behind {@see TenantKeyResolverInterface}, declared via the
 * `apiKeyResolver` module config key (FQCN; exactly one per installation).
 *
 * Denials follow the API envelope (docs/03-development/api-envelope-v1):
 * 401 JSON error body, WWW-Authenticate: Bearer, Cache-Control: no-store.
 * A missing resolver declaration on a keyed route is a config error and
 * throws — fail fast, not open.
 */
class ApiKeyGuard implements RequestGuardInterface
{
    /**
     * Unauthenticated attempts per source /64 and hour. Not a guessing risk
     * (keys are 32 random bytes) — a DISK risk: every 401 writes an api.log
     * line, and an unthrottled flood on shared hosting fills the volume
     * (config-split handoff, point 2). Past the limit: 429, and NO log line —
     * the flood itself is what must stop reaching the disk.
     */
    private const UNAUTH_LIMIT_PER_HOUR = 30;

    private ?ApiPrincipal $principal = null;

    public function __construct(private readonly ModuleManager $moduleManager) {}

    public function enforce(): ?ResponseInterface
    {
        $key = DI::getRequest()->getBearerToken();
        if ($key === null) {
            return $this->unauthThrottled() ?? $this->unauthorized('Missing bearer key.');
        }

        $principal = $this->resolver()->resolve($key);
        if ($principal === null) {
            // Unknown and revoked keys answer identically — no oracle.
            return $this->unauthThrottled() ?? $this->unauthorized('Invalid bearer key.');
        }

        $this->principal = $principal;
        return null;
    }

    /**
     * Counts the failed attempt against the caller's /64; the 429 response
     * once the window is exhausted, null while under it. No usable address
     * (CLI, malformed REMOTE_ADDR) counts nothing and stays on the 401 path —
     * refusing there would block a caller for a fault that is not theirs.
     */
    private function unauthThrottled(): ?ResponseInterface
    {
        $ip = FileThrottle::normalizeIp((string) (DI::getRequest()->getClientIp() ?? ''));
        if ($ip === null) {
            return null;
        }

        $throttle = new FileThrottle(
            rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/api'
        );
        if ($throttle->allow('unauth:' . $ip, self::UNAUTH_LIMIT_PER_HOUR, 3600)) {
            return null;
        }

        return new JsonResponse(
            ['error' => ['code' => 'throttled', 'message' => 'Rate limit exceeded.']],
            429,
            [
                'Retry-After'   => (string) $throttle->retryAfter(3600),
                'Cache-Control' => 'no-store',
            ]
        );
    }

    /** Tenant of the authenticated request. Only valid after enforce() passed. */
    public function getTenantId(): string
    {
        return $this->principal()->tenantId;
    }

    /**
     * Connection identity within the tenant, or null when the project resolves
     * tenants only. Opaque — pass through, never interpret (ApiPrincipal).
     * Only valid after enforce() passed.
     */
    public function getKeyRef(): ?string
    {
        return $this->principal()->keyRef;
    }

    private function principal(): ApiPrincipal
    {
        if ($this->principal === null) {
            throw new \LogicException('No principal resolved — enforce() has not passed.');
        }
        return $this->principal;
    }

    /**
     * Collects `apiKeyResolver` (FQCN) from the module configs. Exactly one
     * declaration per installation — none or several is a config error.
     */
    private function resolver(): TenantKeyResolverInterface
    {
        $fqcns = [];
        foreach ($this->moduleManager->getModuleKeys() as $moduleKey) {
            $fqcn = $this->moduleManager->getModuleConfig($moduleKey)?->get('apiKeyResolver');
            if (is_string($fqcn) && $fqcn !== '') {
                $fqcns[] = $fqcn;
            }
        }

        if (count($fqcns) !== 1) {
            throw new \LogicException(
                count($fqcns) === 0
                    ? 'No apiKeyResolver declared — a stateless keyed route needs one module config declaring the resolver FQCN.'
                    : 'apiKeyResolver declared by more than one module: ' . implode(', ', $fqcns)
            );
        }

        $resolver = new $fqcns[0]();
        if (!$resolver instanceof TenantKeyResolverInterface) {
            throw new \LogicException($fqcns[0] . ' must implement TenantKeyResolverInterface');
        }
        return $resolver;
    }

    private function unauthorized(string $message): JsonResponse
    {
        // Denials never reach the gateway's log call — log them here, tenant `-`.
        ApiLog::line(null, 401);

        return new JsonResponse(
            ['error' => ['code' => 'unauthorized', 'message' => $message]],
            401,
            ['WWW-Authenticate' => 'Bearer', 'Cache-Control' => 'no-store']
        );
    }
}
