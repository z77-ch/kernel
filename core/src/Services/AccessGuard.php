<?php
namespace Z77\Core\Services;

use Z77\Core\Controller\ControllerHandler,
    Z77\Core\DI,
    Z77\Core\Http\Request,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Core\Http\Security\CsrfService,
    Z77\Core\Services\MessageService,
    Z77\Core\Services\ModuleManager,
    Z77\Core\Session\SessionManager,
    Z77\Shared\Attributes\Csrf,
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

        // Declarative #[Csrf] (page-mode form posts — fetch POSTs are covered
        // globally above). Token: X-CSRF-Token header OR `csrf_token` body field.
        $csrfDenied = $this->enforceCsrfAttribute($request);
        if ($csrfDenied !== null) {
            return $csrfDenied;
        }

        $authUser     = $this->authService->getCurrentUser();
        $requiredRole = $this->authService->resolveRoleForCurrentController();

        if (AuthService::hasSufficientRole($authUser, $requiredRole)) {
            return null;
        }

        return $this->buildRedirect();
    }

    /**
     * Enforces the opt-in #[Csrf] action attribute on write methods (non-GET/
     * HEAD). Failure: Fetch → error envelope (mirrors the global fetch check);
     * Page → 303 to the site root (no page-mode message channel — actions that
     * want a friendly failure UX validate in-action instead).
     */
    private function enforceCsrfAttribute(Request $request): ?ResponseInterface
    {
        if ($request->isReadMethod()) {
            return null;
        }

        $ref = new \ReflectionMethod(
            $this->controllerHandler->getCurrentControllerClassName(),
            $this->controllerHandler->getCurrentActionMethod()
        );
        if ($ref->getAttributes(Csrf::class) === []) {
            return null;
        }

        $token = $request->getCsrfToken()
            ?? (string) $request->getPostParameter('csrf_token');

        if ($token !== '' && $this->csrfService->validate($token)) {
            return null;
        }

        if ($request->getMode() === RequestMode::Fetch) {
            $this->messageService->pushFlash('error', 'CSRF token invalid');
            return (new FetchResponse())
                ->setStatus('error')
                ->setFlashes($this->messageService->consumeFlashesForEnvelope());
        }

        return new RedirectResponse('/', 303);
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
