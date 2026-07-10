<?php
namespace Z77\Shared\Services;

use Z77\Shared\Auth\AuthUser,
    Z77\Shared\Entities\LoginUser,
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
    private ?LoginUser $verifiedUser = null;

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
            ])
            : new AuthUser([
                'id'        => 0,
                'user_name' => 'guest',
                'roles'     => [AuthRole::GUEST],
            ]);

        return $this->currentUser;
    }

    public function canLogin(LoginUser $user, string $password): ?self
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
        $this->currentUser = new AuthUser([
            'id'        => $user->getId(),
            'user_name' => $user->getUsername(),
            'roles'     => $user->getRoles(),
        ]);
        $this->sessionManager->set('auth_user', [
            'id'        => $user->getId(),
            'user_name' => $user->getUsername(),
            'roles'     => $user->getRoles(),
        ]);
    }

    public function logout(): void
    {
        $this->currentUser = null;
        $this->sessionManager->remove('auth_user');
    }
}
