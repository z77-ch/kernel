<?php

namespace Z77\Shared\ValueObjects;

/**
 * Transport-agnostic representation of one uploaded file (Phase 3). The `$_FILES`
 * → VO bridge lives in `core/Http/Request` (placement decision C); everything
 * downstream — `UploadService`, `SaveService` — consumes this VO and never touches
 * `$_FILES`, so it stays HTTP-agnostic and testable.
 *
 * The client-supplied MIME type and filename are kept only as hints — the trusted
 * type comes from {@see sniffMime()} (a server-side `finfo` sniff), which the save
 * path feeds to {@see \Z77\Module\Dms\Images\DocumentKind::fromMime()} (the allowlist).
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $originalName,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly string $clientMimeType = '',
        public readonly int $error = UPLOAD_ERR_OK,
    ) {}

    /**
     * Build from one normalised `$_FILES` entry
     * (`['name'=>…, 'tmp_name'=>…, 'size'=>…, 'type'=>…, 'error'=>…]`).
     */
    public static function fromPhpFile(array $file): self
    {
        return new self(
            originalName: (string) ($file['name'] ?? ''),
            tmpPath: (string) ($file['tmp_name'] ?? ''),
            size: (int) ($file['size'] ?? 0),
            clientMimeType: (string) ($file['type'] ?? ''),
            error: (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    /**
     * A real, error-free upload whose temp file is present.
     */
    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->tmpPath !== ''
            && is_file($this->tmpPath);
    }

    /**
     * Raw bytes of the temp file, or null if it cannot be read.
     */
    public function bytes(): ?string
    {
        if (!is_file($this->tmpPath)) {
            return null;
        }
        $bytes = file_get_contents($this->tmpPath);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Server-side sniffed MIME type (never trust {@see $clientMimeType}).
     */
    public function sniffMime(): string
    {
        if (!is_file($this->tmpPath)) {
            return '';
        }
        $info = finfo_open(FILEINFO_MIME_TYPE);
        if ($info === false) {
            return '';
        }
        $mime = finfo_file($info, $this->tmpPath);
        finfo_close($info);

        return $mime ?: '';
    }

    /**
     * Lower-case extension derived from the original filename (without dot).
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * SHA-256 of the temp file, computed by STREAMING the file (`hash_file`) — O(1) memory,
     * so a multi-GB upload is hashed without ever entering PHP memory. Null if unreadable.
     * MUST be called before the temp file is moved into the blob (the path is gone after).
     */
    public function sha256(): ?string
    {
        if (!is_file($this->tmpPath)) {
            return null;
        }
        $hash = @hash_file('sha256', $this->tmpPath);

        return $hash === false ? null : $hash;
    }

    /**
     * Path-based image dimensions (`getimagesize`) as `[width, height]`, or null if the
     * temp file is not a readable image. Used instead of `getimagesizefromstring` so the
     * whole file is not loaded into memory just to read the header. Call before the move.
     */
    public function imageSize(): ?array
    {
        if (!is_file($this->tmpPath)) {
            return null;
        }
        $size = @getimagesize($this->tmpPath);
        if ($size === false) {
            return null;
        }

        return [$size[0] ?? null, $size[1] ?? null];
    }
}
