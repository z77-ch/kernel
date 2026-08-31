<?php
namespace Z77\Shared\Auth;

use Z77\Core\Config\AuthRole;

/**
 * The session identity the ACL reasons about — regardless of which door it
 * came through. Two realms share this shape:
 *
 *   REALM_BACKEND — BackendUser (password login, backendUsers.json), int id.
 *   REALM_MEMBER  — MemberAccount (module-member, passwordless), string id.
 *   REALM_CRON    — the job runner acting on its own (ADR-031), no stored user.
 *
 * The realm keeps the id spaces apart: consumers that map the id back to
 * an entity (CurrentUserService, DMS Principal) MUST check the realm first —
 * a member account id must never be looked up in the backend user store or
 * matched against backend-user ACEs, and a cron actor resolves to no entity at
 * all.
 */
final class AuthUser
{
    public const REALM_BACKEND = 'backend';
    public const REALM_MEMBER  = 'member';

    /**
     * A job running in the CLI. It has no session and no record behind it: the
     * identity exists for audit fields and for services that evaluate ACLs
     * themselves. It authorizes nothing — whoever can start the runner can
     * already do anything the PHP process can (ADR-031).
     */
    public const REALM_CRON = 'cron';

    private int|string $id;
    private string $userName;
    private array $roles;
    private string $realm;

    public function __construct(array $data = [])
    {
        $this->id       = $data['id']        ?? 0;
        $this->userName = $data['user_name'] ?? 'guest';
        $this->roles    = $data['roles']     ?? [AuthRole::GUEST];
        $this->realm    = $data['realm']     ?? self::REALM_BACKEND;
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getRealm(): string
    {
        return $this->realm;
    }

    public function isBackendRealm(): bool
    {
        return $this->realm === self::REALM_BACKEND;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasAtLeast(string $minRole): bool
    {
        return AuthRole::rolesSatisfy($this->roles, $minRole);
    }

    /**
     * Highest role of this user according to AuthRole hierarchy.
     * Returns the AuthRole constant string (e.g. 'admin', 'member').
     * Falls back to GUEST if no known role is assigned.
     */
    public function getHighestRole(): string
    {
        $hierarchy = AuthRole::getRoleHierarchy();
        $bestRole  = AuthRole::GUEST;
        $bestLevel = -1;
        foreach ($this->roles as $role) {
            $level = $hierarchy[$role] ?? -1;
            if ($level > $bestLevel) {
                $bestLevel = $level;
                $bestRole  = $role;
            }
        }
        return $bestRole;
    }

    public function isLoggedIn(): bool
    {
        return $this->id !== 0 && $this->id !== '';
    }
}
