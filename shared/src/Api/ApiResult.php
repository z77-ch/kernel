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
        /** @var array<string,string> field → message of a refused request ({@see invalidFields()}) */
        public readonly array $fields = [],
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
     * Accepted (202, envelope v1 addition 2026-11-12, ADR-058): the request was taken and will
     * have its effect elsewhere — `devices/login` answers it for EVERY address alike, whether a
     * code went out or not. No ETag (nothing to revalidate); $headers as in {@see payload()}.
     *
     * @param array<string,string> $headers
     */
    public static function accepted(array $data, array $headers = []): self
    {
        return new self($data, null, 202, '', '', null, $headers);
    }

    /**
     * Failure. $retryAfter (seconds) only where the envelope defines it
     * (429 throttled, 503 snapshot_pending).
     */
    public static function error(string $code, int $status, string $message, ?int $retryAfter = null, array $fields = []): self
    {
        return new self([], null, $status, $code, $message, $retryAfter, [], $fields);
    }

    /**
     * The request was well-formed but its VALUES were refused (422 `invalid_fields`, envelope
     * v1 addition 2026-11-12): $fields maps each refused field to a message for the person who
     * typed it — German in this framework, since the client shows it under the field. The
     * responder adds them to the error body as `error.fields`; `message` stays the summary.
     *
     * @param array<string,string> $fields field → message, at least one
     */
    public static function invalidFields(array $fields, string $message = 'Some fields were refused.'): self
    {
        if ($fields === []) {
            throw new \InvalidArgumentException('invalidFields() needs at least one field.');
        }
        return self::error('invalid_fields', 422, $message, null, array_map('strval', $fields));
    }

    public function isError(): bool
    {
        return $this->errorCode !== '';
    }
}
