<?php

namespace Z77\Core\Http\Response;

use Z77\Core\Services\LayoutManager;

/**
 * HtmlResponse
 *
 * Carries the context array for HTML rendering, or pre-rendered HTML when
 * served from the page cache. Three construction paths:
 *   - Normal:       new HtmlResponse($layoutManager, $context) — controller path
 *   - Cache hit:    HtmlResponse::fromCache($html, $etag)      — page-cache path
 *   - 304 reply:    HtmlResponse::notModified($etag)           — client-cache path
 *
 * send() is the single point that emits headers and body, so all three paths
 * cannot drift apart (e.g. one sending Cache-Control while the other does not).
 *
 * The Dispatcher tells this response which CacheMode to advertise; this class
 * maps the mode to the concrete Cache-Control header value so the routing
 * layer stays free of HTTP cache syntax.
 *
 * Created via AbstractBaseController::html() helper, not directly in actions.
 */
class HtmlResponse implements ResponseInterface
{
    use EnvelopeFields;

    private ?string $html = null;
    private CacheMode $cacheMode = CacheMode::NoStore;
    private ?PageCacheStatus $cacheStatus = null;
    private ?int $etag = null;
    private bool $omitBody = false;

    public function __construct(
        private ?LayoutManager $layoutManager = null,
        private array $context = []
    ) {}

    /**
     * Builds a response around already-rendered HTML loaded from the page cache.
     * No LayoutManager is needed because nothing will be rendered.
     */
    public static function fromCache(string $html, int $etag): self
    {
        $r = new self();
        $r->html = $html;
        $r->etag = $etag;
        $r->cacheMode = CacheMode::ServerCached;
        return $r;
    }

    /**
     * Builds an empty 304 Not Modified response. The browser holds the body;
     * we ship only the validators (status, Cache-Control, ETag).
     */
    public static function notModified(int $etag): self
    {
        $r = new self();
        $r->html = '';
        $r->etag = $etag;
        $r->cacheMode = CacheMode::NotModified;
        return $r;
    }

    /**
     * Adds or overwrites a single context value.
     * Used by preRender() on the controller to inject controller-wide data.
     */
    public function addContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function setCacheMode(CacheMode $mode): self
    {
        $this->cacheMode = $mode;
        return $this;
    }

    public function setCacheStatus(PageCacheStatus $status): self
    {
        $this->cacheStatus = $status;
        return $this;
    }

    public function setEtag(int $etag): self
    {
        $this->etag = $etag;
        return $this;
    }

    /**
     * Suppresses the response body in send(). Used by the Dispatcher for HEAD
     * requests, which carry response headers identical to GET but no body.
     */
    public function omitBody(bool $omit = true): self
    {
        $this->omitBody = $omit;
        return $this;
    }

    /**
     * Renders the HTML once and returns the string.
     * Memoized — repeated calls return the same string without re-rendering.
     * Used by the page cache to capture output for storage.
     *
     * The view snapshot is built lazily, here at send-time, so any late asset
     * registrations from postExecute() are included.
     *
     * When envelope channels (flashes/messages/commands) are populated, an
     * embedded JSON block is appended so the client-side dispatcher in core.js
     * can run them after inserting the HTML into the DOM. The block is silent
     * for full-page loads (no handler picks it up) and active in fetch popups
     * (popup.show triggers the dispatcher).
     */
    public function getHtml(): string
    {
        if ($this->html === null) {
            $this->html = $this->layoutManager
                ->buildView()
                ->assign($this->context)
                ->render();

            if ($this->hasEnvelopeFields()) {
                $json = json_encode(
                    $this->buildEnvelopeFields(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $json = str_replace('</', '<\/', $json); // safe inside <script>
                $this->html .= "\n<script type=\"application/json\" data-z77-envelope>{$json}</script>\n";
            }
        }
        return $this->html;
    }

    public function send(): void
    {
        $this->assertHeadersNotSent();
        $this->sendHeaders();
        $this->sendBody();
    }

    private function assertHeadersNotSent(): void
    {
        if (!headers_sent($file, $line)) {
            return;
        }
        if (defined('DEBUG') && DEBUG) {
            throw new \LogicException("HtmlResponse::send(): headers already sent in {$file}:{$line}");
        }
        // Production: headers are lost but body still goes out — better than
        // crashing on a misconfigured request lifecycle.
    }

    private function sendHeaders(): void
    {
        if ($this->cacheMode === CacheMode::NotModified) {
            http_response_code(304);
        }

        header('Content-Type: text/html; charset=utf-8');

        header('Cache-Control: ' . match ($this->cacheMode) {
            CacheMode::ServerCached,
            CacheMode::NotModified => 'public, no-cache',
            CacheMode::NoStore     => 'no-store',
        });

        if ($this->etag !== null) {
            header('ETag: "' . $this->etag . '"');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $this->etag) . ' GMT');
        }

        if ($this->cacheStatus !== null) {
            header('X-Z77-PageCache: ' . $this->cacheStatus->value);
        }
    }

    private function sendBody(): void
    {
        // No body for 304 (validators only) or HEAD (caller wants headers, not body).
        if ($this->cacheMode === CacheMode::NotModified || $this->omitBody) {
            return;
        }

        echo $this->getHtml();
    }
}
