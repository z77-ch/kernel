<?php
namespace Z77\Core\Services;

use Z77\Core\DI,
    Z77\Core\Http\Request,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Shared\Api\ApiLog,
    Z77\Shared\Auth\ApiPrincipal,
    Z77\Shared\Auth\DeviceKeyResolverInterface,
    Z77\Shared\Auth\TenantKeyResolverInterface,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * Guard for stateless API routes — a chain of two credentials, no session
 * involved (ADR-057):
 *
 *   1. `Authorization: Bearer <key>` → the tenant resolver (machine channel);
 *      a bearer that does not resolve is a 401, the cookie is not tried.
 *   2. else the device cookie {@see DEVICE_COOKIE} → the device resolver
 *      (device channel), only when an installation declares one;
 *   3. else 401.
 *
 * The device channel is browser-borne, so it is also a CSRF surface: every
 * cookie request must carry `X-Z77-App: 1` and, where the browser sends an
 * `Origin`, it must name this host; a POST must send one. Otherwise 403.
 *
 * One door opens WITHOUT a credential: the code sign-in of an app user
 * (`POST /api/v1/devices/login` and `/verify`, {@see ANONYMOUS_SLUGS}, ADR-058) —
 * it is how a device gets its cookie. Both pass as an anonymous request
 * (principal null) under the same header/Origin rule, and only while a device
 * resolver is declared; module-api throttles them and judges the code. They stay
 * anonymous even when the browser carries a device cookie: a request with a
 * principal passes the idempotency store, which keeps the answer's headers — and
 * verify's `Set-Cookie` carries the plaintext secret.
 *
 * Verification only — key storage, administration, and revocation are behind
 * {@see TenantKeyResolverInterface} (`apiKeyResolver`, project code, exactly
 * one when a bearer is used) and {@see DeviceKeyResolverInterface}
 * (`apiDeviceResolver`, module-api, at most one).
 *
 * Denials follow the API envelope (docs/03-development/api-envelope-v1):
 * JSON error body, Cache-Control: no-store, 401 with WWW-Authenticate: Bearer.
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

    /** The device cookie (ADR-057): HttpOnly, Secure, SameSite=Strict, path `/api`. */
    public const DEVICE_COOKIE = 'z77_api_device';

    /**
     * The anonymous door (ADR-058): the slugs of `POST /api/v1/devices/login` and
     * `POST /api/v1/devices/verify` — nothing else passes without a credential.
     */
    public const ANONYMOUS_SLUGS = [['v1', 'devices', 'login'], ['v1', 'devices', 'verify']];

    private ?ApiPrincipal $principal = null;
    private bool $passed = false;

    public function __construct(private readonly ModuleManager $moduleManager) {}

    public function enforce(): ?ResponseInterface
    {
        $request = DI::getRequest();

        $key = $request->getBearerToken();
        if ($key !== null) {
            $principal = $this->resolver()->resolve($key);
            if ($principal === null) {
                // Unknown and revoked keys answer identically — no oracle.
                return $this->unauthThrottled() ?? $this->unauthorized('Invalid bearer key.');
            }
            return $this->pass($principal);
        }

        $devices = $this->deviceResolver();
        if ($devices !== null) {
            // The sign-in first: a phone carrying a dead cookie must still be able to sign in anew.
            if (self::isAnonymousRequest($request)) {
                return $this->appOriginDenied($request) ?? $this->pass(null);
            }

            $cookie = $request->getCookie(self::DEVICE_COOKIE);
            if ($cookie !== null) {
                $denied = $this->appOriginDenied($request);
                if ($denied !== null) {
                    return $denied;
                }
                $principal = $devices->resolve($cookie);
                if ($principal === null || !$principal->isDevice()) {
                    // Unknown, revoked, expired, app user inactive, person gone — one answer.
                    return $this->unauthThrottled() ?? $this->unauthorized('Invalid device.');
                }
                return $this->pass($principal);
            }
        }

        return $this->unauthThrottled() ?? $this->unauthorized('Missing bearer key.');
    }

    /**
     * Who called: the machine or device principal, or null for the anonymous
     * sign-in calls. Only valid after enforce() passed.
     */
    public function getPrincipal(): ?ApiPrincipal
    {
        if (!$this->passed) {
            throw new \LogicException('No principal resolved — enforce() has not passed.');
        }
        return $this->principal;
    }

    /** Tenant of the authenticated request. Only valid after enforce() passed with a principal. */
    public function getTenantId(): string
    {
        return $this->principal()->tenantId;
    }

    /**
     * Connection identity within the tenant, or null when the project resolves
     * tenants only. Opaque — pass through, never interpret (ApiPrincipal).
     * Only valid after enforce() passed with a principal.
     */
    public function getKeyRef(): ?string
    {
        return $this->principal()->keyRef;
    }

    private function pass(?ApiPrincipal $principal): null
    {
        $this->principal = $principal;
        $this->passed    = true;
        return null;
    }

    private function principal(): ApiPrincipal
    {
        if ($this->principal === null) {
            throw new \LogicException('No principal resolved — enforce() has not passed.');
        }
        return $this->principal;
    }

    /** A POST to one of {@see ANONYMOUS_SLUGS}. Public: the gateway asks the same question (second lock). */
    public static function isAnonymousRequest(Request $request): bool
    {
        return $request->getMethod() === 'post' && self::isAnonymousPath($request->getSlugs());
    }

    /** @param list<string> $slugs `[v1, devices, login]` */
    public static function isAnonymousPath(array $slugs): bool
    {
        return in_array($slugs, self::ANONYMOUS_SLUGS, true);
    }

    /**
     * The CSRF rule of the device channel (ADR-057): the app header is mandatory;
     * an Origin, where sent, must be this host; a POST must send one (every
     * current browser does). A 403 envelope when violated, null when fine.
     * GETs from the app may lack an Origin — same-origin GETs do not send one.
     */
    private function appOriginDenied(Request $request): ?JsonResponse
    {
        if (!$request->isAppRequest()) {
            return $this->forbidden('Missing X-Z77-App header.');
        }

        $origin = $request->getOrigin();
        if ($origin === null) {
            return $request->isReadMethod() ? null : $this->forbidden('Missing Origin header.');
        }

        return self::originMatchesHost($origin, $request->getHost())
            ? null
            : $this->forbidden('Foreign origin.');
    }

    /** `https://example.ch[:port]` against the Host header (`example.ch[:port]`). */
    private static function originMatchesHost(string $origin, string $host): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host']) || $host === '') {
            return false;
        }
        $authority = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $authority === $host;
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

    /**
     * Collects `apiKeyResolver` (FQCN) from the module configs. Exactly one
     * declaration per installation — none or several is a config error.
     */
    private function resolver(): TenantKeyResolverInterface
    {
        $fqcns = $this->declared('apiKeyResolver');

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

    /**
     * Collects `apiDeviceResolver` (FQCN). None = the device channel is off;
     * several, or a wrong type, is a config error.
     */
    private function deviceResolver(): ?DeviceKeyResolverInterface
    {
        $fqcns = $this->declared('apiDeviceResolver');
        if ($fqcns === []) {
            return null;
        }
        if (count($fqcns) > 1) {
            throw new \LogicException('apiDeviceResolver declared by more than one module: ' . implode(', ', $fqcns));
        }

        $resolver = new $fqcns[0]();
        if (!$resolver instanceof DeviceKeyResolverInterface) {
            throw new \LogicException($fqcns[0] . ' must implement DeviceKeyResolverInterface');
        }
        return $resolver;
    }

    /** @return list<string> the non-empty FQCN strings declared under $key across the module configs */
    private function declared(string $key): array
    {
        $fqcns = [];
        foreach ($this->moduleManager->getModuleKeys() as $moduleKey) {
            $fqcn = $this->moduleManager->getModuleConfig($moduleKey)?->get($key);
            if (is_string($fqcn) && $fqcn !== '') {
                $fqcns[] = $fqcn;
            }
        }
        return $fqcns;
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

    private function forbidden(string $message): JsonResponse
    {
        ApiLog::line(null, 403);

        return new JsonResponse(
            ['error' => ['code' => 'forbidden', 'message' => $message]],
            403,
            ['Cache-Control' => 'no-store']
        );
    }
}
