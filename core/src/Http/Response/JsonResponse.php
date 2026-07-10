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
    public function __construct(
        private array $data,
        private int $status = 200
    ) {}

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
