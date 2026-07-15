<?php

namespace Z77\Core\Controller;

use Z77\Core\Services\LayoutManager,
    Z77\Core\Services\MessageService,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\FileResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\Response\VoidResponse,
    Z77\Core\Http\Response\NoContentResponse,
    Z77\Core\Http\RequestMode,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\DI
;

/**
 * @method void preExecute()   Optional hook — runs before the action. Define in concrete controller for auth checks or shared setup. Throw an exception to abort.
 * @method void postExecute()  Optional hook — runs after the action. Define in concrete controller to add controller-wide assets or shared data.
 */
abstract class AbstractBaseController
{
    protected string $actionMethod;
    protected LayoutManager $layoutManager;
    protected MessageService $messageService;

    public function __construct(string $actionMethod)
    {
        $this->actionMethod = $actionMethod;
    }

    public function run(): ResponseInterface
    {
        $this->layoutManager = new LayoutManager(
            DI::getControllerHandler(),
            DEBUG
        );
        $this->messageService = DI::getMessageService();
        // initialize() is called lazily in html() — only when HTML output is needed

        if (method_exists($this, 'preExecute')) {
            $this->preExecute();
        }

        $response = $this->execute();

        if (method_exists($this, 'postExecute')) {
            $this->postExecute();
        }

        return $response;
    }

    private function execute(): ResponseInterface
    {
        $actionMethod = $this->actionMethod;
        if (method_exists($this, $actionMethod)) {
            return $this->$actionMethod();
        }
        throw new NotFoundException("Action not found: {$actionMethod}");
    }

    // -------------------------------------------------------------------------
    // Optional hooks — define in concrete controller if needed, do NOT call parent
    // -------------------------------------------------------------------------
    //
    // preExecute(): void
    //   Runs before the action. Use for auth checks or shared setup.
    //   Throw an exception to abort — do not return a value.
    //
    //   Example:
    //     protected function preExecute(): void {
    //         if (!$this->auth->isLoggedIn()) {
    //             throw new UnauthorizedException();
    //         }
    //     }
    //
    // postExecute(): void
    //   Runs after the action, before send(). Use to add controller-wide
    //   assets or prepare shared data for all actions in this controller.
    //
    //   Example:
    //     protected function postExecute(): void {
    //         $this->layoutManager->addJs('my-controller', $this->nameSpace);
    //     }
    //
    // -------------------------------------------------------------------------
    // Response helpers — use these in actions instead of instantiating directly
    // -------------------------------------------------------------------------

    /**
     * Returns an HTML response for the current action.
     * LayoutManager decides full page or AJAX fragment automatically.
     */
    protected function html(array $context = []): HtmlResponse
    {
        $this->layoutManager->initialize();
        $navigationService            = DI::getInstance()->get('NavigationService');
        $navigation                   = $navigationService->getCurrent();
        $language                     = DI::getRequest()->getLanguage();
        $context['navigationService'] = $navigationService;
        $context['navigation']        = $navigation;
        $context['language']          = $language;
        $context['languageSwitch']    = $this->buildLanguageSwitch($language);
        $context['seo']               = $this->buildSeoLinks($language);
        $context['metaData']          = $navigation
            ? $navigationService->findMetaData($navigation->getId(), $language)
            : null;
        $context['csrfToken']         = DI::getCsrfService()->getToken();
        $context['clientI18n']        = DI::getTranslator()->clientDictionary($language);

        // Consume session feedback only on full page loads; fetch-mode partials
        // (popup HTML) must not eat the buffers intended for the next page render.
        $context['_flashes']  = [];
        $context['_messages'] = [];
        if (DI::getRequest()->getMode() === RequestMode::Page) {
            $context['_flashes']  = $this->messageService->consumeFlashesForPage();
            $context['_messages'] = $this->messageService->consumeMessagesForPage();
        }

        $response = new HtmlResponse($this->layoutManager, $context);

        // Fetch-mode partials (popup HTML) deliver in-place feedback through
        // the embedded envelope block — flashes pushed during the action are
        // dispatched by the client-side handler after the popup is mounted.
        if (DI::getRequest()->getMode() === RequestMode::Fetch) {
            $response
                ->setFlashes($this->messageService->consumeFlashesForEnvelope())
                ->setMessages($this->messageService->consumeMessagesForEnvelope());
        }

        return $response;
    }

    /**
     * Builds the language-switch list for the current environment (ADR-013):
     * the module's offered languages (config key `languages`, opt-in) filtered to
     * the global i18n whitelist. Each item carries the target URL (same page,
     * re-prefixed) and whether it is the active language. Empty when the module
     * offers fewer than two languages → the template renders no switch.
     *
     * @return list<array{code: string, url: string, active: bool}>
     */
    /**
     * Canonical base path of the current page for canonical/hreflang + language-switch
     * links (ADR-015): the current navigation's canonical NavigationAlias path plus any
     * captured content slugs — NOT the as-requested path. A non-canonical alias (e.g.
     * `/extra` pointing at the same navigation as the canonical `/home`) must still emit
     * `/home` as its canonical. Falls back to the requested path on convention routes
     * (no navigation entry). The default-language (canonical) form; callers localize it.
     */
    private function currentCanonicalPath(): string
    {
        $request           = DI::getRequest();
        $navigationService = DI::getInstance()->get('NavigationService');
        $urlResolver       = DI::getInstance()->get('NavigationUrlResolver');
        $nav               = $navigationService->getCurrent();

        if ($nav !== null) {
            $base = $urlResolver->urlFor($nav);
            if ($base !== '') {
                $slugs = $request->getSlugs();
                return $slugs === [] ? $base : $base . '/' . implode('/', $slugs);
            }
        }
        return $request->getPathWithoutLanguage();
    }

    private function buildLanguageSwitch(string $current): array
    {
        $request = DI::getRequest();
        $i18n    = DI::getI18n();
        $offered = array_values(array_filter(
            DI::getModuleManager()->getModuleLanguages($request->getModule()),
            fn($code) => $i18n->isValidLanguage($code)
        ));

        if (count($offered) < 2) {
            return [];
        }

        $base = $this->currentCanonicalPath();

        return array_map(fn($code) => [
            'code'   => $code,
            'url'    => localizedUrl($base, $code),
            'active' => $code === $current,
        ], $offered);
    }

    /**
     * SEO link data for the document head (ADR-014): a self-referencing canonical
     * (the localized URL of the current language — the one form the 301 funnels to)
     * plus hreflang alternates for every language the environment offers, with an
     * `x-default` pointing at the default language. Absolute URLs — hreflang requires
     * them. The canonical is always set; alternates only when the environment offers
     * more than one language (a single-language area needs no hreflang set).
     *
     * @return array{canonical: string, alternates: list<array{hreflang: string, url: string}>}
     */
    private function buildSeoLinks(string $current): array
    {
        $request = DI::getRequest();
        $i18n    = DI::getI18n();
        $origin  = url_origin($_SERVER);
        $base    = $this->currentCanonicalPath();   // canonical alias path (+ content slugs)

        $canonical = $origin . localizedUrl($base, $current);

        $offered = array_values(array_filter(
            DI::getModuleManager()->getModuleLanguages($request->getModule()),
            fn($code) => $i18n->isValidLanguage($code)
        ));

        if (count($offered) < 2) {
            return ['canonical' => $canonical, 'alternates' => []];
        }

        $alternates = array_map(fn($code) => [
            'hreflang' => $code,
            'url'      => $origin . localizedUrl($base, $code),
        ], $offered);

        // x-default → the default-language version (search-engine fallback target).
        $alternates[] = [
            'hreflang' => 'x-default',
            'url'      => $origin . localizedUrl($base, $i18n->getDefaultLanguage()),
        ];

        return ['canonical' => $canonical, 'alternates' => $alternates];
    }

    /**
     * Returns a FetchResponse with the current in-place flash/message buffers
     * already merged into the envelope. Controllers should push via
     * $this->messageService->pushFlash()/pushMessage() and then return
     * $this->fetch()->addCommand(...) — never instantiate FetchResponse directly.
     */
    protected function fetch(): FetchResponse
    {
        return (new FetchResponse())
            ->setFlashes($this->messageService->consumeFlashesForEnvelope())
            ->setMessages($this->messageService->consumeMessagesForEnvelope());
    }

    /**
     * Returns a JSON response.
     */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    /**
     * Returns a file download response.
     */
    protected function file(string $path, string $filename, ?string $mimeType = null): FileResponse
    {
        return new FileResponse($path, $filename, $mimeType);
    }

    /**
     * Returns an HTTP redirect response.
     */
    protected function redirect(string $url, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    /**
     * Returns a void response — no output, clean termination.
     */
    protected function void(): VoidResponse
    {
        return new VoidResponse();
    }

    /**
     * Returns a 204 No Content response — success without a body.
     * For fetch endpoints that signal "up to date / nothing to deliver"
     * (e.g. revalidation: data unchanged). Not for error cases.
     */
    protected function noContent(): NoContentResponse
    {
        return new NoContentResponse();
    }
}
