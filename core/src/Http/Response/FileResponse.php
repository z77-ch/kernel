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
 *   files resume. Honours `If-None-Match` → `304` when an ETag is set.
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

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $this->disposition . '; filename="' . addslashes($this->filename) . '"');
        header('Cache-Control: ' . ($this->cacheControl ?? 'private, no-cache'));

        if ($this->etag !== null) {
            header('ETag: "' . $this->etag . '"');
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $this->etag . '"') {
                http_response_code(304);
                return;
            }
        }

        $this->streamPhp();
    }

    /**
     * Range-aware PHP streaming. Full body (200) without a Range header, otherwise the
     * requested byte range (206); an unsatisfiable range yields 416.
     */
    private function streamPhp(): void
    {
        $size = filesize($this->path);
        header('Accept-Ranges: bytes');

        $start = 0;
        $end   = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';

        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            if ($m[1] === '' && $m[2] === '') {
                $this->unsatisfiable($size);
                return;
            }
            if ($m[1] === '') {
                // suffix: last N bytes
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end   = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            }

            if ($start > $end || $start >= $size) {
                $this->unsatisfiable($size);
                return;
            }

            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }

        header('Content-Length: ' . ($end - $start + 1));
        $this->emit($start, $end);
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
