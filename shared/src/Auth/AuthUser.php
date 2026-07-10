<?php
namespace Z77\Shared\Auth;

use Z77\Core\Config\AuthRole;

final class AuthUser
{
    private int $id;
    private string $userName;
    private array $roles;

    public function __construct(array $data = [])
    {
        $this->id       = $data['id']       ?? 0;
        $this->userName = $data['user_name'] ?? 'guest';
        $this->roles    = $data['roles']     ?? [AuthRole::GUEST];
    }

    public function getId(): int
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
        return $this->id > 0;
    }
}
