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

    public function resolveRoleForCurrentController(): string
    {
        $controllerFqcn = $this->controllerHandler->getCurrentControllerClassName();
        $actionMethod   = $this->controllerHandler->getCurrentActionMethod();
        $module         = $this->controllerHandler->getCurrentModule();
        $group          = $this->controllerHandler->getCurrentGroup();

        // Config keys use the short class name without namespace (e.g. 'LoginController'),
        // nested under the group so base names only need to be unique within a group.
        $controllerName = Naming::toClassBaseName($controllerFqcn);

        $moduleConfig = DI::getModuleManager()->getModuleConfig($module);
        $defaultRole  = $moduleConfig->get('moduleRole') ?? AuthRole::GUEST;

        $groupControllers = $moduleConfig->get('controllers')[$group] ?? [];
        $controllerConfig = $groupControllers[$controllerName] ?? $groupControllers['*'] ?? null;

        $controllerRole = $defaultRole;
        $actionRole     = null;

        if ($controllerConfig) {
            $controllerRole = $controllerConfig['controllerRole'] ?? $defaultRole;
            $actions        = $controllerConfig['actions'] ?? [];
            $actionRole     = $actions[$actionMethod] ?? $actions['*'] ?? null;
        }

        return $actionRole ?? $controllerRole ?? $defaultRole;
    }

    public function getCurrentUser(): AuthUser
    {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        $data = $this->sessionManager->get('auth_user', null);

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
            'id'        => $user->getId(),
            'user_name' => $user->getUsername(),
            'roles'     => $user->getRoles(),
            'realm'     => AuthUser::REALM_BACKEND,
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
