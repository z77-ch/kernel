<?php

namespace Z77\Core\Http\Response;

/**
 * NoContentResponse
 *
 * Sends HTTP 204 No Content. No body, no Content-Type header, no LayoutManager.
 * preRender() is never called for this response type.
 *
 * Use for fetch endpoints that signal "up to date / nothing to deliver"
 * (e.g. background revalidation: data unchanged → 204) — instantly
 * distinguishable from a real delivery in the network tab. 204 means
 * success without content; it is not an error signal.
 *
 * Usage in action:
 *   return $this->noContent();
 */
class NoContentResponse implements ResponseInterface
{
    public function send(): void
    {
        http_response_code(204);
    }
}
