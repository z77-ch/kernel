<?php

namespace Z77\Shared\Mail;

/**
 * An image a mail shows INLINE (2026-10-23, the e-mail logo): a readable PNG, JPEG or GIF
 * file with its real pixel size and the alt text. What an {@see InlineImageSource} answers;
 * {@see InlineImages} embeds it ({@see Message::embed()}) and writes the `<img>`.
 *
 * Why the pixel size travels along: Outlook (desktop, the Word engine) draws an image at its
 * INTRINSIC size and ignores CSS widths — so the file must already be the display size, and
 * the `width` / `height` attributes are the image's own (divided by the declared density, see
 * {@see fromFile()}). Nothing is resized here. SVG is not taken: most mail clients do not show it.
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
     *
     * `$density` (2026-11-20, the mandator's logo set-up): how many file pixels make one
     * display pixel. 1 = the file is the display size;
     * 2 = a file made for high-density screens (the variant module-mandator derives) — the
     * `width` / `height` it answers are then half the pixel size, and Outlook, which honours
     * the HTML attributes, draws it at that size.
     */
    public static function fromFile(string $path, string $alt = '', int $density = 1): ?self
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if (!is_array($info) || !in_array($info[2] ?? null, self::TYPES, true) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return null;
        }
        $density = max(1, $density);

        return new self(
            $path,
            max(1, intdiv((int) $info[0], $density)),
            max(1, intdiv((int) $info[1], $density)),
            trim((string) preg_replace('/\s+/u', ' ', $alt)),
        );
    }
}
