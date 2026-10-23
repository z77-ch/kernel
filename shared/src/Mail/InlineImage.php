<?php

namespace Z77\Shared\Mail;

/**
 * An image a mail shows INLINE (2026-10-23, the e-mail logo): a readable PNG, JPEG or GIF
 * file with its real pixel size and the alt text. What an {@see InlineImageSource} answers;
 * {@see InlineImages} embeds it ({@see Message::embed()}) and writes the `<img>`.
 *
 * Why the pixel size travels along: Outlook (desktop, the Word engine) draws an image at its
 * INTRINSIC size and ignores CSS widths — so the file must already be the display size, and
 * the `width` / `height` attributes are the image's own. Nothing is resized here (owner
 * 2026-10-23: the operator uploads the right size). SVG is not taken: most mail clients do
 * not show it.
 */
final class InlineImage
{
    /** What mail clients show everywhere. */
    public const TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF];

    private function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly string $alt,
    ) {
    }

    /**
     * The image of `$path`, or null when there is none to show: missing, unreadable, not a
     * PNG / JPEG / GIF (judged by the file's header, not its name), or without a size.
     */
    public static function fromFile(string $path, string $alt = ''): ?self
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if (!is_array($info) || !in_array($info[2] ?? null, self::TYPES, true) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return null;
        }

        return new self($path, (int) $info[0], (int) $info[1], trim((string) preg_replace('/\s+/u', ' ', $alt)));
    }
}
