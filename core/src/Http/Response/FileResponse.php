<?php

namespace Z77\Core\Http\Response;

/**
 * FileResponse
 *
 * Serves a file with the right headers. Two concerns beyond a plain download
 * (added for the DMS, ADR-016):
 *
 * - **Disposition** — `attachment` (download) or `inline` (show in the browser).
 * - **Range requests** — PHP streams the bytes itself and answers `Range:` with `206
 *   Partial Content` (+ `Accept-Ranges`/`Content-Range`), so videos seek/resume and large
 *   files resume. Honours `If-None-Match` ({@see Etag}) → `304` when an ETag is set.
 *   One ranged answer carries at most {@see RANGE_MAX_BYTES} (DMS-CHUNK-DL-001): the host
 *   caps a response body (cyon: 500 MB); `Content-Range` names exactly the slice sent and
 *   the true total.
 * - **Delivery policy** — {@see DeliveryPolicy} decides the final type, disposition and CSP
 *   (DMS-ACTIVE-001); `$disposition` is a request, an active type never renders inline.
 *
 * Byte transfer is **always** the portable PHP range-stream — it runs on every target
 * (built-in server, shared hosting like cyon, Windows dev). Web-server-accelerated
 * delivery (Apache `X-Sendfile`, nginx `X-Accel-Redirect`, LiteSpeed) was rejected: it
 * is not portable (cyon ships no `mod_xsendfile`) and the PHP stream is sufficient for
 * the low-volume, authenticated protected bytes this serves — see ADR-017.
 *
 * Usage in action:
 *   return $this->file('/var/data/invoice-42.pdf', 'invoice-42.pdf', 'application/pdf');
 */
class FileResponse implements ResponseInterface
{
    public const ATTACHMENT = 'attachment';
    public const INLINE     = 'inline';

    private const CHUNK = 8192;

    /** Most bytes ONE ranged answer (206) carries — the reasoning stands at {@see ByteRange::MAX_BYTES}. */
    public const RANGE_MAX_BYTES = ByteRange::MAX_BYTES;

    public function __construct(
        private string $path,
        private string $filename,
        private ?string $mimeType = null,
        private string $disposition = self::ATTACHMENT,
        private ?string $etag = null,
        private ?string $cacheControl = null,
    ) {}

    public function send(): void
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException("FileResponse: file not found at '{$this->path}'");
        }

        $mime = $this->mimeType ?? mime_content_type($this->path) ?: 'application/octet-stream';

        DeliveryPolicy::apply($mime, $this->disposition === self::INLINE, $this->filename);
        header('Cache-Control: ' . ($this->cacheControl ?? 'private, no-cache'));

        if ($this->etag !== null) {
            header('ETag: ' . Etag::header($this->etag));

            // {@see Etag} owns the reading of the header — before it, this
            // was the only door that compared the raw value against one
            // quoted string, so a client echoing `W/"…"` or sending a list
            // re-downloaded the whole file every time.
            if (Etag::matches($_SERVER['HTTP_IF_NONE_MATCH'] ?? null, $this->etag)) {
                http_response_code(304);
                return;
            }
        }

        $this->streamPhp();
    }

    /**
     * Range-aware PHP streaming. Full body (200) without a Range header, otherwise the
     * requested byte range, capped at {@see RANGE_MAX_BYTES} (206); an unsatisfiable range
     * yields 416.
     */
    private function streamPhp(): void
    {
        $size = filesize($this->path);
        header('Accept-Ranges: bytes');

        $start = 0;
        $end   = $size - 1;
        $range = self::byteRange($_SERVER['HTTP_RANGE'] ?? '', $size);

        if ($range === false) {
            $this->unsatisfiable($size);
            return;
        }
        if ($range !== null) {
            [$start, $end] = $range;
            http_response_code(206);
            // Always the TRUE total: a capped slice must never look like the whole file.
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }

        header('Content-Length: ' . ($end - $start + 1));
        $this->emit($start, $end);
    }

    /**
     * {@see ByteRange::parse()}. `$max` is a parameter only so the harness can exercise the
     * cap on a small file (`tests/file-response-range.php`, reflection); production always
     * passes {@see RANGE_MAX_BYTES}.
     *
     * @return array{0: int, 1: int}|false|null
     */
    private static function byteRange(string $header, int $size, int $max = self::RANGE_MAX_BYTES): array|false|null
    {
        return ByteRange::parse($header, $size, $max);
    }

    private function unsatisfiable(int $size): void
    {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
    }

    private function emit(int $start, int $end): void
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("FileResponse: cannot open '{$this->path}'");
        }

        fseek($handle, $start);
        $remaining = $end - $start + 1;

        while ($remaining > 0 && !feof($handle)) {
            $buffer = fread($handle, (int) min(self::CHUNK, $remaining));
            if ($buffer === false) {
                break;
            }
            echo $buffer;
            $remaining -= strlen($buffer);
            flush();
        }

        fclose($handle);
    }
}
