<?php
namespace Z77\Shared\Services;

use Z77\Shared\Auth\AuthUser,
    Z77\Shared\Entities\BackendUser,
    Z77\Shared\Libraries\Convention\Naming,
    Z77\Core\Session\SessionManager,
    Z77\Core\Controller\ControllerHandler,
    Z77\Core\Config\AuthRole,
    Z77\Core\DI
;

class AuthService
{
    private SessionManager $sessionManager;
    private ControllerHandler $controllerHandler;

    private ?AuthUser  $currentUser  = null;
    private ?BackendUser $verifiedUser = null;

    public function __construct(SessionManager $sessionManager, ControllerHandler $controllerHandler)
    {
        $this->sessionManager    = $sessionManager;
        $this->controllerHandler = $controllerHandler;
    }

    public static function hasSufficientRole(AuthUser $authUser, string $requiredRole): bool
    {
        $hierarchy   = AuthRole::getRoleHierarchy();
        $userRoles   = $authUser->getRoles();
        $userMax     = 0;

        foreach ($userRoles as $role) {
            if (isset($hierarchy[$role])) {
                $userMax = max($userMax, $hierarchy[$role]);
            }
        }

        $requiredLevel = $hierarchy[$requiredRole] ?? PHP_INT_MAX;

        return $userMax >= $requiredLevel;
    }

    /**
     * The role the current request's controller/action requires — what
     * AccessGuard compares the session against. Delegates to
     * {@see resolveRoleFor()}; the backend menu (ADR-045) asks the same method
     * for every entry, so what a user sees and what he may call cannot differ.
     */
    public function resolveRoleForCurrentController(): string
    {
        // Config keys use the short class name without namespace (e.g. 'LoginController'),
        // nested under the group so base names only need to be unique within a group.
        return $this->resolveRoleFor(
            $this->controllerHandler->getCurrentModule(),
            $this->controllerHandler->getCurrentGroup(),
            Naming::toClassBaseName($this->controllerHandler->getCurrentControllerClassName()),
            $this->controllerHandler->getCurrentActionMethod()
        ) ?? '';  // unknown module: '' is no role → hasSufficientRole() denies everyone
    }

    /**
     * The role an arbitrary target requires, read from that module's config.
     * Null when the module is not installed (there is nothing to reach).
     *
     * @param string $controllerBaseName short class name, e.g. 'ContentController'
     * @param string $actionMethod       method name, e.g. 'listAction'
     */
    public function resolveRoleFor(string $module, string $group, string $controllerBaseName, string $actionMethod): ?string
    {
        $moduleConfig = DI::getModuleManager()->getModuleConfig($module);
        if ($moduleConfig === null) {
            return null;
        }

        return self::requiredRole(
            $moduleConfig->get('moduleRole'),
            $moduleConfig->get('controllers') ?? [],
            $group,
            $controllerBaseName,
            $actionMethod
        );
    }

    /**
     * The access rule itself, pure (config values in, role out) so it can be
     * checked without a running application (tests/backend-access.php).
     *
     * Action role > controller role > module role > GUEST. Config lookup is
     * controllers[$group][$controllerBaseName] (or the group wildcard '*'),
     * then its 'actions'[$actionMethod] (or the action wildcard '*').
     */
    public static function requiredRole(
        ?string $moduleRole,
        array $controllers,
        string $group,
        string $controllerBaseName,
        string $actionMethod
    ): string {
        $defaultRole = $moduleRole ?? AuthRole::GUEST;

        $groupControllers = $controllers[$group] ?? [];
        $controllerConfig = $groupControllers[$controllerBaseName] ?? $groupControllers['*'] ?? null;

        $controllerRole = $defaultRole;
        $actionRole     = null;

        if ($controllerConfig) {
            $controllerRole = $controllerConfig['controllerRole'] ?? $defaultRole;
            $actions        = $controllerConfig['actions'] ?? [];
            $actionRole     = $actions[$actionMethod] ?? $actions['*'] ?? null;
        }

        return $actionRole ?? $controllerRole ?? $defaultRole;
    }

    /**
     * May $user call this route? Takes URL segments as a navigation entry
     * stores them (`backend`, `content`, `meta-data`, `list`); an empty action
     * means the controller's default action, resolved the way the Request does.
     * False for a module that is not installed.
     */
    public function canReach(AuthUser $user, string $module, string $group, string $controller, string $action): bool
    {
        $moduleManager = DI::getModuleManager();
        if ($action === '') {
            $action = $moduleManager->getDefaultActionForController($module, $group, $controller)
                ?? $moduleManager->getDefaultAction($module)
                ?? '';
        }

        $required = $this->resolveRoleFor(
            $module,
            $group,
            Naming::toCamelCase($controller) . 'Controller',
            Naming::toActionMethod($action)
        );

        return $required !== null && self::hasSufficientRole($user, $required);
    }

    public function getCurrentUser(): AuthUser
    {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        $data = $this->sessionManager->get('auth_user', null);

        if ($data && !$this->sessionIsCurrent($data)) {
            $this->sessionManager->remove('auth_user');
            $data = null;
        }

        $this->currentUser = $data
            ? new AuthUser([
                'id'        => $data['id'],
                'user_name' => $data['user_name'],
                'roles'     => $data['roles'] ?? [AuthRole::GUEST],
                'realm'     => $data['realm'] ?? AuthUser::REALM_BACKEND,
            ])
            : new AuthUser([
                'id'        => 0,
                'user_name' => 'guest',
                'roles'     => [AuthRole::GUEST],
            ]);

        return $this->currentUser;
    }

    /**
     * Is this session still valid against the stored user? Backend realm only — a member-realm id
     * lives in another id space, and cron or API identities have no `auth_user` at all. Runs once
     * per request (getCurrentUser() memoizes).
     *
     * A password change raises the user's stamp, which ends the sessions carrying the old one
     * ({@see BackendUser::bumpAuthEpoch()}). A session without a stamp and a user without the field
     * both read 0, so a deploy ends nothing. A user no longer in the store ends the session too:
     * until now his session kept the roles it was started with.
     *
     * A lookup that FAILS (corrupt store, no DI in an offline harness) proves nothing about this
     * session: it stays valid with a log line, as it was before the stamp existed.
     */
    private function sessionIsCurrent(array $data): bool
    {
        if (($data['realm'] ?? AuthUser::REALM_BACKEND) !== AuthUser::REALM_BACKEND) {
            return true;
        }

        try {
            $user = DI::getUnifiedEntityManager()->getRepository(BackendUser::class)->find((int) ($data['id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('AuthService: session stamp not checked — ' . $e->getMessage());
            return true;
        }

        return $user instanceof BackendUser && self::stampMatches($data, $user);
    }

    /** The rule itself, pure: a missing stamp reads 0, a mismatch in either direction is stale. */
    public static function stampMatches(array $sessionData, BackendUser $user): bool
    {
        return (int) ($sessionData['auth_epoch'] ?? 0) === $user->getAuthEpoch();
    }

    /**
     * The user changed his OWN password: he stays signed in here while his other sessions end.
     * Does nothing for any other session, so the caller need not ask whose password it was.
     */
    public function renewSessionStamp(BackendUser $user): void
    {
        $data = $this->sessionManager->get('auth_user', null);
        if (!is_array($data)
            || ($data['realm'] ?? AuthUser::REALM_BACKEND) !== AuthUser::REALM_BACKEND
            || (int) ($data['id'] ?? 0) !== $user->getId()
        ) {
            return;
        }

        $data['auth_epoch'] = $user->getAuthEpoch();
        $this->sessionManager->set('auth_user', $data);
    }

    public function canLogin(BackendUser $user, string $password): ?self
    {
        if (!password_verify($password, $user->getPasswordHash())) {
            return null;
        }
        $this->verifiedUser = $user;
        return $this;
    }

    public function login(): void
    {
        if ($this->verifiedUser === null) {
            throw new \LogicException('login() requires a prior successful canLogin()');
        }
        $user = $this->verifiedUser;
        $this->verifiedUser = null;

        $this->sessionManager->regenerate();
        $payload = [
            'id'         => $user->getId(),
            'user_name'  => $user->getUsername(),
            'roles'      => $user->getRoles(),
            'realm'      => AuthUser::REALM_BACKEND,
            'auth_epoch' => $user->getAuthEpoch(),
        ];
        $this->currentUser = new AuthUser($payload);
        $this->sessionManager->set('auth_user', $payload);
    }

    public function logout(): void
    {
        $this->currentUser = null;
        $this->sessionManager->remove('auth_user');
    }

    // ── member realm (ADR-029 second decision) ─────────────────────────────
    //
    // The member session (module-member) is the source of truth for WHO is
    // signed in as a customer; these two methods are its per-request
    // projection into the ACL identity, called by the module's AuthBridge
    // BEFORE AccessGuard resolves roles. They never regenerate the session id
    // (fixation defense lives in MemberSession start/end) and they never
    // touch a backend identity: an existing backend login always wins.

    /** Projects a signed-in member account into the ACL identity. */
    public function establishMemberIdentity(string $accountId, string $displayName, array $roles): void
    {
        $existing = $this->sessionManager->get('auth_user', null);
        if ($existing !== null && ($existing['realm'] ?? AuthUser::REALM_BACKEND) === AuthUser::REALM_BACKEND) {
            return; // backend session present — never downgrade it
        }

        $payload = [
            'id'        => $accountId,
            'user_name' => $displayName,
            'roles'     => $roles,
            'realm'     => AuthUser::REALM_MEMBER,
        ];
        $this->currentUser = new AuthUser($payload);
        $this->sessionManager->set('auth_user', $payload);
    }

    /** Removes a member-realm identity (sign-out, idle expiry, account gone). */
    public function clearMemberIdentity(): void
    {
        $existing = $this->sessionManager->get('auth_user', null);
        if ($existing === null || ($existing['realm'] ?? AuthUser::REALM_BACKEND) !== AuthUser::REALM_MEMBER) {
            return;
        }
        $this->currentUser = null;
        $this->sessionManager->remove('auth_user');
    }
}
