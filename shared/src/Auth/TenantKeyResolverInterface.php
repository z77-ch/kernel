<?php

namespace Z77\Shared\Auth;

/**
 * Resolves an API bearer key to the calling identity — the seam between the
 * framework's ApiKeyGuard (verification) and project-owned key administration
 * (storage, backend UI, revocation). Declared per installation via the
 * `apiKeyResolver` module config key (FQCN, same pattern as `authBridges`);
 * the implementation lives in project code.
 *
 * Contract for implementations:
 *   - keys are stored HASHED (SHA-256 of the plain key; keys are high-entropy
 *     random values, no KDF needed) — never in plaintext
 *   - comparison uses hash_equals() on the hash
 *   - up to two active keys per connection must resolve (rotation window)
 *   - a revoked or unknown key returns null — the guard answers 401 without
 *     distinguishing the two (no oracle)
 *   - the returned {@see ApiPrincipal} names the tenant and MAY name the
 *     connection (`keyRef`) when one tenant holds several — see ApiPrincipal
 *     for the keyRef conditions (opaque, never the key/hash, optional)
 */
interface TenantKeyResolverInterface
{
    /**
     * @param string $plainKey the bearer value as sent by the client
     * @return ApiPrincipal|null the calling identity, or null when no active key matches
     */
    public function resolve(string $plainKey): ?ApiPrincipal;
}
