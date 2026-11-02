<?php

namespace Z77\Core\Http\Response;

/**
 * The ranged answer of {@see FileResponse} over a {@see ByteSource} instead of one file on
 * disk (DMS-ZIP-001): the same 256 MiB cap per range ({@see ByteRange}), ETag / 304, 206 with
 * the true total in `Content-Range`, 416, and 200 without `Range`. `documents/chunk-download.js`
 * cannot tell the two apart. Type, disposition, nosniff and the sandbox are {@see DeliveryPolicy}'s,
 * as for every class that sends file bytes (DMS-ACTIVE-001).
 *
 * A HEAD ignores `Range` (RFC 9110: it is defined for GET) and answers the headers of the
 * whole. The request's values are constructor arguments: whoever builds this response reads
 * them through `Request` (Rule 4), usually because it has to gate on the same slice first.
 *
 * **A source that fails while streaming** ends the body short, nothing more. `Content-Length`
 * is out by then; an error page appended here could fill up the promised length with HTML,
 * which a client would accept as bytes. A short body is what the chunked client retries.
 *
 * Built by a service, like `DocumentService::serve()` builds a {@see FileResponse} (Rule 3).
 */
class ByteSourceResponse implements ResponseInterface
{
    /** Bytes emitted between two flushes. */
    private const SLICE = 1024 * 1024;

    public function __construct(
        private ByteSource $source,
        private string $filename,
        private string $mimeType,
        private string $etag,
        private string $rangeHeader = '',
        private ?string $ifNoneMatch = null,
        private bool $head = false,
        private string $cacheControl = 'private, no-cache',
    ) {}

    public function send(): void
    {
        // Always a download: nothing assembled from positions is shown in a browser.
        DeliveryPolicy::apply($this->mimeType, false, $this->filename);
        header('Cache-Control: ' . $this->cacheControl);
        header('ETag: ' . Etag::header($this->etag));

        if (Etag::matches($this->ifNoneMatch, $this->etag)) {
            http_response_code(304);
            return;
        }

        $size = $this->source->size();
        header('Accept-Ranges: bytes');

        $range = $this->head ? null : ByteRange::parse($this->rangeHeader, $size);
        if ($range === false) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            return;
        }

        $start = 0;
        $end   = $size - 1;
        if ($range !== null) {
            [$start, $end] = $range;
            http_response_code(206);
            // Always the TRUE total: a capped slice must never look like the whole.
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Content-Length: ' . ($end - $start + 1));

        if (!$this->head && $end >= $start) {
            $this->stream($start, $end);
        }
    }

    private function stream(int $start, int $end): void
    {
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new \RuntimeException('ByteSourceResponse: cannot open the output stream.');
        }

        try {
            for ($pos = $start; $pos <= $end && !connection_aborted(); $pos += self::SLICE) {
                $this->source->emit($pos, min($end, $pos + self::SLICE - 1), $out);
                flush();
            }
        } catch (\Throwable $e) {
            error_log('ByteSourceResponse: stopped at a read failure — ' . $e->getMessage());
        } finally {
            fclose($out);
        }
    }
}
