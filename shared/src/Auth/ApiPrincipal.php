<?php

namespace Z77\Shared\Auth;

/**
 * WHO called the API. Two channels share this shape (ADR-057):
 *
 *   - MACHINE (bearer key): the tenant, and optionally WHICH of the tenant's
 *     connections (api-principal handoff, 2026-09-02). A tenant can hold
 *     several connections, each delivering a different selection, each the
 *     unit of revocation — the widget established the pattern before the API.
 *   - DEVICE (HttpOnly device cookie, {@see DeviceKeyResolverInterface}): a
 *     signed-in phone or PC, the APP USER it was signed in as (ADR-058: an account
 *     type of its own, linked to exactly one person — a backend user, later a
 *     member), that person's name, and the app user's scopes as they hold NOW
 *     (filtered live against the person's roles). Built by {@see device()}; its
 *     tenant is the constant {@see DEVICE_TENANT} — a device belongs to the
 *     installation, not to a tenant, and a machine service never sees one
 *     (devices only reach services that declare a scope, ScopedApiServiceInterface).
 *
 * `keyRef` is OPAQUE to the framework: the project assigns and interprets it,
 * the framework only passes it through (into ApiRequest, the throttle key,
 * and the api.log line). It MUST NOT be the key or its hash — it appears in
 * logs. A short stable slug of the connection (`[a-z0-9-]`) is the intended
 * shape. Projects resolving only a tenant return null and run unchanged.
 *
 * The device fields are DATA for the target service (who captured a receipt),
 * never an identity to act as: there is no "run as user" on the stateless path.
 */
final class ApiPrincipal
{
    /** The tenant column of every device principal (log, throttle). */
    public const DEVICE_TENANT = 'device';

    /**
     * @param list<string> $scopes      device only: what the device may reach now (`receipt.capture`, …)
     * @param string|null  $ownerName   device only: the linked person's name — the name of the app
     *                                  user (it has none of its own, ADR-058)
     * @param string|null  $deviceLabel device only: the device's name («Safari · iPhone») — a target
     *                                  records it with what it captured (E8)
     * @param string|null  $appUserId   device only: the app user the device is signed in as
     * @param string|null  $linkedRealm device only: `backend` or `member` ({@see AuthUser} realms)
     * @param string|null  $linkedId    device only: the linked person's id in that realm
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $keyRef = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $ownerName = null,
        public readonly array $scopes = [],
        public readonly ?string $deviceLabel = null,
        public readonly ?string $appUserId = null,
        public readonly ?string $linkedRealm = null,
        public readonly ?string $linkedId = null,
    ) {
    }

    /**
     * A signed-in device (ADR-058). `owner_id` of ADR-057 is gone from the principal: who
     * captured is the linked person — realm and id, never a backend id alone.
     *
     * @param list<string> $scopes the app user's scopes, already filtered by the person's roles
     */
    public static function device(
        string $deviceId,
        string $deviceLabel,
        string $appUserId,
        string $linkedRealm,
        string $linkedId,
        string $ownerName,
        array $scopes,
    ): self {
        return new self(
            self::DEVICE_TENANT,
            null,
            $deviceId,
            $ownerName,
            array_values($scopes),
            $deviceLabel,
            $appUserId,
            $linkedRealm,
            $linkedId,
        );
    }

    public function isDevice(): bool
    {
        return $this->deviceId !== null;
    }

    /** True only for a device granted that scope — a machine principal has no scopes. */
    public function hasScope(string $scope): bool
    {
        return $this->isDevice() && in_array($scope, $this->scopes, true);
    }

    /** Log/throttle rendering: `8`, `8:widget-a`, or `device:3`. */
    public function label(): string
    {
        if ($this->isDevice()) {
            return 'device:' . $this->deviceId;
        }
        return $this->keyRef === null ? $this->tenantId : $this->tenantId . ':' . $this->keyRef;
    }
}
