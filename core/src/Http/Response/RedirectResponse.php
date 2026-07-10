<?php

namespace Z77\Core\Http\Response;

/**
 * RedirectResponse
 *
 * Sends an HTTP redirect header. No output, no LayoutManager.
 * preRender() is never called for this response type.
 *
 * Usage in action:
 *   return $this->redirect('/login');
 *   return $this->redirect('/dashboard', 301);
 *
 * To carry a flash or message across the redirect, push it via MessageService
 * (e.g. $this->messageService->pushFlashAfterRedirect(...)) before returning.
 */
class RedirectResponse implements ResponseInterface
{
    public function __construct(
        private string $url,
        private int $status = 302
    ) {}

    public function send(): void
    {
        http_response_code($this->status);
        header('Location: ' . $this->url);
    }
}
