<?php

namespace Z77\Shared\Mail;

/**
 * A file attached to a {@see Message} (DMS Phase 6, ADR-016 / OPEN-5). Holds the raw
 * bytes in memory — the consumer (e.g. `DocumentService::send()`) reads them from
 * `BlobStorage` and hands them over; the mailer base64-encodes them into the MIME body.
 *
 * The filename is sanitised here (basename + CR/LF stripped) so it can never break out
 * of the MIME header it lands in — attachment filenames are an injection surface.
 *
 * With a `$contentId` the part is INLINE (2026-10-23, the e-mail logo): {@see MimeMessage}
 * puts it into a `multipart/related` next to the HTML, which shows it as `<img src="cid:…">`
 * — {@see Message::embed()} builds one. The id is `local@domain` without the angle brackets
 * and is checked here: it lands in a header (`Content-ID: <…>`) and in the HTML.
 */
final class Attachment
{
    /** The shape a Content-ID may have here (RFC 2392 msg-id, restricted to a safe subset). */
    public const CONTENT_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}@[A-Za-z0-9][A-Za-z0-9.-]{0,63}$/';

    public readonly string $filename;
    public readonly string $mimeType;

    public function __construct(
        string $filename,
        string $mimeType,
        public readonly string $bytes,
        public readonly ?string $contentId = null,
    ) {
        $this->filename = self::sanitiseFilename($filename);
        $this->mimeType = self::sanitiseHeaderValue($mimeType !== '' ? $mimeType : 'application/octet-stream');
        if ($contentId !== null && preg_match(self::CONTENT_ID_PATTERN, $contentId) !== 1) {
            throw new \InvalidArgumentException("Invalid Content-ID '{$contentId}' (expected local@domain, letters, digits, . _ -).");
        }
    }

    public function isInline(): bool
    {
        return $this->contentId !== null;
    }

    private static function sanitiseFilename(string $name): string
    {
        // Keep only the base name (no path), strip control chars incl. CR/LF/quotes.
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F"\\\\]+/', '', $name) ?? '';
        $name = trim($name);
        return $name !== '' ? $name : 'attachment';
    }

    private static function sanitiseHeaderValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
