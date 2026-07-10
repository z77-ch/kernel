<?php
namespace Z77\Core\Config;

class AuthRole
{
    public const SUPER_USER = 'superUser';
    public const ADMIN = 'admin';
    public const CRON_JOB = 'cronJob';
    public const MEMBER = 'member';
    public const VISITOR = 'visitor';
    public const GUEST = 'guest';

    public static function getRoleHierarchy():array
    {
        return [
            self::GUEST     => 0,
            self::VISITOR    => 10,
            self::MEMBER     => 20,
            self::CRON_JOB   => 30,
            self::ADMIN      => 80,
            self::SUPER_USER => 100,
        ];
    }

    /**
     * True when the highest hierarchy level among $roles reaches $minRole — the
     * single source of the "max role level >= threshold" rule shared by
     * {@see \Z77\Shared\Auth\AuthUser::hasAtLeast} and the user-admin
     * admin-capable check. An unknown role (in $roles or $minRole) contributes
     * level 0 — the permissive fallback.
     *
     * NOTE: this is deliberately NOT the fail-secure form used by
     * {@see \Z77\Shared\Services\AuthService::hasSufficientRole} (an unknown
     * REQUIRED role denies everyone). That security-critical dispatch gate keeps
     * its own semantics — do not route it through here.
     *
     * @param string[] $roles
     */
    public static function rolesSatisfy(array $roles, string $minRole): bool
    {
        $hierarchy = self::getRoleHierarchy();
        $minLevel  = $hierarchy[$minRole] ?? 0;
        $userMax   = 0;
        foreach ($roles as $role) {
            $userMax = max($userMax, $hierarchy[$role] ?? 0);
        }
        return $userMax >= $minLevel;
    }
}
