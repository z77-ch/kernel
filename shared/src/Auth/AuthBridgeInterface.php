<?php
namespace Z77\Shared\Auth;

/**
 * A module-owned identity source that projects its own session state into the
 * framework ACL identity (`auth_user`) — the seam that lets a second login
 * door (e.g. module-member's passwordless customer login) feed the same
 * AccessGuard as the backend password login (ADR-029, second decision).
 *
 * Registered per module config under the top-level key `authBridges` (list of
 * FQCNs, zero-arg constructible). AccessGuard calls every registered bridge
 * once per request, BEFORE resolving the current user. A bridge must be cheap
 * when its door shows no sign of use (no session keys, no cookie), and it must
 * only ever manage identities of its own realm.
 */
interface AuthBridgeInterface
{
    /** Reconcile this bridge's session state with the ACL identity. */
    public function sync(): void;
}
