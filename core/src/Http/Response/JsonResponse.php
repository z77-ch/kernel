<?php

namespace Z77\Core\Http\Response;

/**
 * JsonResponse
 *
 * Encodes data as JSON and sends with correct Content-Type header.
 * No LayoutManager involved.
 *
 * Usage in action:
 *   return $this->json(['success' => true, 'id' => $id]);
 *   return $this->json(['error' => 'Not found'], 404);
 */
class JsonResponse implements ResponseInterface
{
    private bool $omitBody = false;

    /**
     * @param array<string,string> $headers additional response headers
     *        (e.g. WWW-Authenticate, Cache-Control, Retry-After, ETag)
     */
    public function __construct(
        private array $data,
        private int $status = 200,
        private array $headers = []
    ) {}

    /** HEAD: same status and headers as GET, no body (mirrors HtmlResponse::omitBody()). */
    public function omitBody(): self
    {
        $this->omitBody = true;
        return $this;
    }

    /** For the API request log — the status the response will send. */
    public function getStatus(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        // 304 carries no body and no Content-Type by definition.
        if ($this->status === 304) {
            return;
        }
        // HEAD (omitBody) mirrors GET's headers — Content-Type included.
        header('Content-Type: application/json; charset=utf-8');
        if ($this->omitBody) {
            return;
        }
        echo json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
