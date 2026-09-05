<?php

namespace Z77\Shared\Api;

/**
 * What an {@see ApiServiceInterface} returns — payload or a typed error.
 * The api module's responder maps it onto the wire (status codes, headers,
 * the frozen error body shape — api-envelope-v1); a service never does.
 *
 * Error `code` values are frozen envelope surface (`unknown_dataset`,
 * `snapshot_pending`, `internal`, …) — the envelope doc owns the list.
 */
final class ApiResult
{
    private function __construct(
        public readonly array $data,
        public readonly ?string $etag,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $errorMessage,
        public readonly ?int $retryAfter,
        /** @var array<string,string> service headers, passed through on 200 and 304 */
        public readonly array $headers = [],
    ) {
    }

    /**
     * Success. Pass the payload's content hash as $etag (propbase: the
     * curatedHash) and conditional GET works without the service comparing
     * anything — the responder answers 304 when the client is current.
     *
     * $headers are the service's own wire headers (`X-Axo3-Retry-After`,
     * `X-Axo3-Revalidate-After`, …): the responder passes them through on
     * 200 AND on 304 — a hint that only rides on a body would be blind
     * exactly when the client is current. The framework does not interpret
     * them; `Cache-Control` and `ETag` stay the responder's and win on a
     * name clash. Errors carry no service headers (the envelope owns those).
     *
     * @param array<string,string> $headers
     */
    public static function payload(array $data, ?string $etag = null, array $headers = []): self
    {
        return new self($data, $etag, 200, '', '', null, $headers);
    }

    /**
     * Failure. $retryAfter (seconds) only where the envelope defines it
     * (429 throttled, 503 snapshot_pending).
     */
    public static function error(string $code, int $status, string $message, ?int $retryAfter = null): self
    {
        return new self([], null, $status, $code, $message, $retryAfter);
    }

    public function isError(): bool
    {
        return $this->errorCode !== '';
    }
}
