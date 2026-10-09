<?php

namespace Z77\Core\Http\Response;

/**
 * BytesResponse
 *
 * Serves bytes that exist in MEMORY only — a PDF just rendered, a CSV just
 * assembled — with the headers a file gets: `Content-Type`,
 * `Content-Disposition` (inline to show in the browser, attachment to
 * download) and `Content-Length`. The counterpart of {@see FileResponse},
 * which streams a file ON DISK (ranges, ETag); a generated document has no
 * path and no reason for a temp file.
 *
 * Added 2026-10-06 with the kernel's PDF facade (`Z77\Shared\Pdf`): the first
 * consumer is module-debtor's invoice PDF, rendered on request from the
 * document's snapshot and never stored (debtor.md).
 *
 * Usage in action:
 *   return $this->bytes($pdf->output(), 'rechnung-12.pdf', 'application/pdf');            // inline
 *   return $this->bytes($csv, 'export.csv', 'text/csv; charset=utf-8', inline: false);     // download
 */
class BytesResponse implements ResponseInterface
{
    public function __construct(
        private string $content,
        private string $filename,
        private string $mimeType,
        private bool $inline = true,
    ) {}

    public function send(): void
    {
        header('Content-Type: ' . $this->mimeType);
        header('Content-Disposition: ' . ($this->inline ? 'inline' : 'attachment') . '; filename="' . addslashes($this->filename) . '"');
        header('Content-Length: ' . strlen($this->content));
        header('Cache-Control: private, no-cache');
        echo $this->content;
    }
}
