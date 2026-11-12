<?php

namespace Z77\Shared\Api;

use Z77\Shared\Auth\ApiPrincipal,
    Z77\Shared\ValueObjects\UploadedFile
;

/**
 * Everything an {@see ApiServiceInterface} may know about the request —
 * deliberately NOT the framework Request: a service sees the authenticated
 * principal and its parameters, nothing transport-level.
 *
 * The first five fields are the machine channel's original shape (positional
 * callers keep working); the rest came with the device channel (ADR-057) and
 * are named arguments with neutral defaults.
 */
final class ApiRequest
{
    /**
     * @param string            $tenantId    tenant resolved by the ApiKeyGuard (`device` for a
     *                                       device principal, '' for the anonymous sign-in calls)
     * @param list<string>      $slugs       path segments after the endpoint (e.g. a dataset name)
     * @param array<string,mixed> $params    query parameters, passed through untouched (e.g. lang)
     * @param string|null       $ifNoneMatch raw If-None-Match header value, if the client sent one
     * @param string|null       $keyRef      WHICH of the tenant's connections called (opaque,
     *                                       project-assigned — see ApiPrincipal); null when the
     *                                       project resolves tenants only
     * @param string            $method      lower-case HTTP method (`get`, `head`, `post`)
     * @param array<string,mixed> $body      POST only: the form fields, or the decoded JSON body
     *                                       when the request sent no form fields
     * @param array<string,list<UploadedFile>> $files POST only: uploaded files per form field
     * @param ApiPrincipal|null $principal   who called; null only for the anonymous sign-in calls (ADR-058)
     * @param string|null       $idempotencyKey the `Idempotency-Key` header of a POST, if sent
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly array $slugs = [],
        public readonly array $params = [],
        public readonly ?string $ifNoneMatch = null,
        public readonly ?string $keyRef = null,
        public readonly string $method = 'get',
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly ?ApiPrincipal $principal = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    /** The device principal, or null on the machine channel (and the sign-in calls). */
    public function device(): ?ApiPrincipal
    {
        return $this->principal !== null && $this->principal->isDevice() ? $this->principal : null;
    }

    /** First uploaded file of a form field, or null. */
    public function file(string $field): ?UploadedFile
    {
        return $this->files[$field][0] ?? null;
    }
}
