<?php
namespace Z77\Core\Services;

use Z77\Core\DI,
    Z77\Core\Http\Request,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Shared\Security\CrossOriginPolicyInterface,
    Z77\Shared\Security\FormStamp,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * Guard of a #[CrossOriginPost] door (CROSS-ORIGIN-POST-001, security.md «cross-origin POST door»):
 * one action a form on a foreign site posts to — no session, no CSRF header, no preflight.
 * Wired by Bootstrap in place of AccessGuard once the action carries the attribute AND the module
 * config names it under `crossOriginPosts`.
 *
 * Checks, in this order, before the action runs (the first that fails answers):
 *
 *   1. POST                                   → 405
 *   2. Fetch mode (Sec-Fetch-Mode present, not navigate; cors and no-cors pass) — a classic
 *      cross-site <form> navigates and is refused, it would reach the action unchecked
 *                                             → 403 mode
 *   3. Content-Type application/x-www-form-urlencoded (the one simple-request body a form sends
 *      without a preflight)                   → 415
 *   4. Content-Length present and ≤ MAX_BODY  → 413
 *   5. Origin present and well-formed — no fallback to Referer
 *                                             → 403 origin
 *   6. the project's policy allows the origin → 403 origin_denied
 *   7. a FormStamp for this route in the body, 3 s – 2 h old; no key configured = shut
 *                                             → 403 stamp
 *   8. per-IP throttle (/64)                  → 429
 *
 * Every answer of the door carries CORS headers so the foreign form can read why: the checked
 * origin from step 6 on, `*` before it (no credentials travel, so `*` opens nothing). Never
 * `Access-Control-Allow-Credentials`. A refusal is `{"status":"error","reason":"…"}`.
 *
 * What the guard does NOT do (the project's action): a throttle per widget / per form, field
 * validation, storage. ⚠️ A stamp is not single-use (FormStamp) — those throttles are the answer.
 */
final class CrossOriginPostGuard implements RequestGuardInterface
{
    public const MAX_BODY      = 16384;
    public const IP_PER_HOUR   = 20;
    public const THROTTLE_DIR  = 'var/lib/throttle/cross-origin';

    private const ORIGIN_PATTERN = '~^https?://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{1,5})?$~';

    /** The origin step 6 accepted — the value of Access-Control-Allow-Origin from then on. */
    private ?string $checkedOrigin = null;

    /**
     * @param string $route      the door as named in `crossOriginPosts` (`group/controller/action`)
     * @param string $policyFqcn its CrossOriginPolicyInterface implementation
     */
    public function __construct(
        private string $route,
        private string $policyFqcn,
        private ?string $baseDir = null
    ) {}

    public function enforce(): ?ResponseInterface
    {
        $request = DI::getRequest();

        if (!$request->isPost()) {
            return $this->refuse('method', 405, ['Allow' => 'POST']);
        }
        if ($request->getMode() !== RequestMode::Fetch) {
            return $this->refuse('mode', 403);
        }
        if ($request->getContentType() !== 'application/x-www-form-urlencoded') {
            return $this->refuse('content_type', 415);
        }
        $length = $request->getContentLength();
        if ($length === null || $length > self::MAX_BODY) {
            return $this->refuse('too_large', 413);
        }

        $origin = strtolower(trim((string) $request->getOrigin()));
        if (preg_match(self::ORIGIN_PATTERN, $origin) !== 1) {
            return $this->refuse('origin', 403);
        }
        if (!$this->policy()->allows($origin, $request)) {
            return $this->refuse('origin_denied', 403);
        }
        $this->checkedOrigin = $origin;

        $stamps = FormStamp::fromConfig($this->baseDir);
        $stamp  = $request->getPostParameter(FormStamp::FIELD);
        if ($stamps === null || !is_string($stamp) || !$stamps->verify($stamp, $this->route)) {
            return $this->refuse('stamp', 403);
        }

        return $this->throttled($request);
    }

    /**
     * Called by the Dispatcher with the action's answer: it must be a JsonResponse (the door has
     * no page, no session, no envelope), and it leaves with the door's CORS headers.
     */
    public function finish(ResponseInterface $response): JsonResponse
    {
        if (!$response instanceof JsonResponse) {
            throw new \LogicException(
                'A #[CrossOriginPost] action must answer with $this->json(), got ' . $response::class . '.'
            );
        }

        return $response->withHeaders($this->corsHeaders());
    }

    private function throttled(Request $request): ?ResponseInterface
    {
        $ip = FileThrottle::normalizeIp((string) ($request->getClientIp() ?? ''));
        if ($ip === null) {
            return null;   // no usable address (CLI): nothing to count, like ApiKeyGuard
        }

        $throttle = new FileThrottle($this->base() . '/' . self::THROTTLE_DIR);
        if ($throttle->allow($this->route . ':' . $ip, self::IP_PER_HOUR, 3600)) {
            return null;
        }

        return $this->refuse('throttled', 429, ['Retry-After' => (string) $throttle->retryAfter(3600)]);
    }

    private function policy(): CrossOriginPolicyInterface
    {
        $policy = new ($this->policyFqcn)();
        if (!$policy instanceof CrossOriginPolicyInterface) {
            throw new \LogicException($this->policyFqcn . ' must implement CrossOriginPolicyInterface');
        }

        return $policy;
    }

    /** @param array<string,string> $extra */
    private function refuse(string $reason, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'error', 'reason' => $reason],
            $status,
            $this->corsHeaders() + $extra
        );
    }

    /** @return array<string,string> */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => $this->checkedOrigin ?? '*',
            'Vary'                        => 'Origin',
            'Cache-Control'               => 'no-store',
        ];
    }

    private function base(): string
    {
        return rtrim(str_replace('\\', '/', $this->baseDir ?? ABS_BASE_PATH), '/');
    }
}
