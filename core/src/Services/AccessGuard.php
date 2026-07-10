<?php
namespace Z77\Core\Services;

use Z77\Core\Controller\ControllerHandler,
    Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Core\Http\Security\CsrfService,
    Z77\Core\Services\MessageService,
    Z77\Core\Services\ModuleManager,
    Z77\Core\Session\SessionManager,
    Z77\Shared\Services\AuthService
;

class AccessGuard
{
    private AuthService $authService;
    private SessionManager $sessionManager;
    private ControllerHandler $controllerHandler;
    private ModuleManager $moduleManager;
    private CsrfService $csrfService;
    private MessageService $messageService;

    public function __construct(
        AuthService $authService,
        SessionManager $sessionManager,
        ControllerHandler $controllerHandler,
        ModuleManager $moduleManager,
        CsrfService $csrfService,
        MessageService $messageService
    ) {
        $this->authService       = $authService;
        $this->sessionManager    = $sessionManager;
        $this->controllerHandler = $controllerHandler;
        $this->moduleManager     = $moduleManager;
        $this->csrfService       = $csrfService;
        $this->messageService    = $messageService;
    }

    public function enforce(): ?ResponseInterface
    {
        $request = DI::getRequest();

        if ($request->getMode() === RequestMode::Fetch && $request->isPost()) {
            $token = $request->getCsrfToken();
            if ($token === null || !$this->csrfService->validate($token)) {
                $this->messageService->pushFlash('error', 'CSRF token invalid');
                return (new FetchResponse())
                    ->setStatus('error')
                    ->setFlashes($this->messageService->consumeFlashesForEnvelope());
            }
        }

        $authUser     = $this->authService->getCurrentUser();
        $requiredRole = $this->authService->resolveRoleForCurrentController();

        if (AuthService::hasSufficientRole($authUser, $requiredRole)) {
            return null;
        }

        return $this->buildRedirect();
    }

    private function buildRedirect(): RedirectResponse
    {
        $module = $this->controllerHandler->getCurrentModule();

        $this->sessionManager->set('access.origin', [
            'module'     => $module,
            'group'      => $this->controllerHandler->getCurrentGroup(),
            'controller' => $this->controllerHandler->getCurrentControllerClassName(),
            'action'     => $this->controllerHandler->getCurrentActionMethod(),
            'get'        => DI::getRequest()->getQueryParameters(),
        ]);

        $loginUrl = $this->moduleManager
            ->getModuleConfig($module)
            ->get('loginUrl', '/login');

        return new RedirectResponse($loginUrl);
    }
}
