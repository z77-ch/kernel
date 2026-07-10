<?php

namespace Z77\Core\Routing;

use Z77\Core\DI,
    Z77\Core\Exception\ExceptionHandler,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Request,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\CacheMode,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\Response\PageCacheStatus,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Core\Libraries\CacheManager,
    Z77\Core\Services\AccessGuard,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Attributes\Page
;

class Dispatcher
{
    private CacheManager $cacheManager;
    private PageCachePolicy $policy;
    private AccessGuard $accessGuard;

    public function __construct(
        CacheManager $cacheManager,
        PageCachePolicy $policy,
        AccessGuard $accessGuard
    ) {
        $this->cacheManager = $cacheManager;
        $this->policy       = $policy;
        $this->accessGuard  = $accessGuard;
    }

    public function execute(): void
    {
        $controller = DI::getControllerHandler()->getCurrentControllerInstance();
        $request    = DI::getRequest();

        try {
            $this->enforceActionConstraints($controller, $request);

            $denied = $this->accessGuard->enforce();

            if ($denied !== null) {
                $response = $denied;
            } else {
                // The session is started by AccessGuard above. Reconcile the request
                // language with it now — before the page-cache decision keys on the
                // language (ADR-013 / ADR-015): a URL prefix persists the choice; the
                // rendered language is never taken from the session. A remembered
                // preference only redirects the bare site root to the localized root.
                $rootRedirect = $request->applyLanguageSession(DI::getInstance()->get('SessionManager'));

                if ($rootRedirect !== null) {
                    $response = new RedirectResponse($rootRedirect, 302);
                } else {
                    $decision = $this->policy->decide($request);
                    $response = $this->resolveResponse($decision, $controller);

                    // HEAD: same headers as GET, but body is suppressed in send().
                    if ($request->isHead() && $response instanceof HtmlResponse) {
                        $response->omitBody();
                    }
                }
            }

            $response->send();

        } catch (NotFoundException $e) {
            ExceptionHandler::handle($e);
        }

        if (DEBUG) {
            $this->cacheManager->flush();
        } else {
            register_shutdown_function(function() {
                $this->cacheManager->flush();
            });
        }
    }

    /**
     * Enforces declarative constraints on the resolved action method:
     *   #[Fetch] / #[Page]     — restrict to a single RequestMode
     *   #[HttpMethod(...)]     — restrict to listed HTTP methods
     *
     * Missing attribute = no constraint = action handles dispatch itself.
     * Violation throws NotFoundException — caught by execute() and rendered
     * by ExceptionHandler (HTML for Page, JSON for Fetch).
     */
    private function enforceActionConstraints(object $controller, Request $request): void
    {
        $ref = new \ReflectionMethod($controller, DI::getControllerHandler()->getCurrentActionMethod());

        if ($ref->getAttributes(Fetch::class) !== [] && $request->getMode() !== RequestMode::Fetch) {
            throw new NotFoundException('Action requires Fetch mode');
        }
        if ($ref->getAttributes(Page::class) !== [] && $request->getMode() !== RequestMode::Page) {
            throw new NotFoundException('Action requires Page mode');
        }

        $methodAttrs = $ref->getAttributes(HttpMethod::class);
        if ($methodAttrs !== []) {
            $allowed = $methodAttrs[0]->newInstance()->methods;
            if (!in_array(strtoupper($request->getMethod()), $allowed, true)) {
                throw new NotFoundException('Action does not accept method: ' . strtoupper($request->getMethod()));
            }
        }
    }

    /**
     * Builds the response for the chosen cache mode. Each branch returns a
     * fully prepared response (cache mode + ETag where applicable) so the
     * caller only has to invoke send().
     *
     *   PageFromClientCache → empty 304 with the ETag the policy already knows
     *   PageFromCache       → load body from disk; on miss, render and store
     *   NewPage             → render fresh, no cache touched
     */
    private function resolveResponse(PageCacheDecision $decision, object $controller): ResponseInterface
    {
        if ($decision->mode === PageCachePolicyMode::PageFromClientCache) {
            return HtmlResponse::notModified($decision->etag);
        }

        if ($decision->mode === PageCachePolicyMode::PageFromCache) {
            $cached = $this->cacheManager->page()->get($decision->identity);
            if ($cached !== null) {
                $cached->setCacheStatus(PageCacheStatus::Hit);
                return $cached;
            }
            // Cache miss despite policy expecting a hit — render and store.
            $this->resolveNavigation();
            $response = $controller->run();
            $this->tryStore($decision, $response);
            return $response;
        }

        // NewPage — render fresh, do not cache.
        $this->resolveNavigation();
        $response = $controller->run();
        if ($response instanceof HtmlResponse) {
            $response
                ->setCacheMode(CacheMode::NoStore)
                ->setCacheStatus(PageCacheStatus::Bypass);
        }
        return $response;
    }

    /**
     * Stores the response in the page cache and stamps it with the resulting ETag.
     * Cache write failures (disk full, permission denied, etc.) are caught: the
     * fresh response is still served, only its caching is skipped — the request
     * succeeds even when the cache backend does not.
     */
    private function resolveNavigation(): void
    {
        $request = DI::getRequest();
        $navigationService = DI::getInstance()->get('NavigationService');
        $navigationService->resolveCurrent(
            $request->getModule(),
            $request->getGroup(),
            $request->getController(),
            $request->getAction(),
            $request->getQueryParameters()
        );

        $via = $request->getGetParameter('via');
        $navigationService->resolveUiCurrent($via !== null ? (int)$via : null);
    }

    private function tryStore(PageCacheDecision $decision, mixed $response): void
    {
        if (!$response instanceof HtmlResponse) {
            return;
        }
        try {
            $etag = $this->cacheManager->page()->set(
                $decision->identity,
                $response,
                $decision->ttl
            );
            $response
                ->setEtag($etag)
                ->setCacheMode(CacheMode::ServerCached)
                ->setCacheStatus(PageCacheStatus::Miss);
        } catch (\Throwable $e) {
            error_log('PageCache write failed: ' . $e->getMessage());
            $response
                ->setCacheMode(CacheMode::NoStore)
                ->setCacheStatus(PageCacheStatus::Bypass);
        }
    }
}
