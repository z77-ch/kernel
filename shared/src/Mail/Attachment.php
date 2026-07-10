<?php

namespace Z77\Shared\Mail;

/**
 * A file attached to a {@see Message} (DMS Phase 6, ADR-016 / OPEN-5). Holds the raw
 * bytes in memory — the consumer (e.g. `DocumentService::send()`) reads them from
 * `BlobStorage` and hands them over; the mailer base64-encodes them into the MIME body.
 *
 * The filename is sanitised here (basename + CR/LF stripped) so it can never break out
 * of the MIME header it lands in — attachment filenames are an injection surface.
 */
final class Attachment
{
    public readonly string $filename;
    public readonly string $mimeType;

    public function __construct(string $filename, string $mimeType, public readonly string $bytes)
    {
        $this->filename = self::sanitiseFilename($filename);
        $this->mimeType = self::sanitiseHeaderValue($mimeType !== '' ? $mimeType : 'application/octet-stream');
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
