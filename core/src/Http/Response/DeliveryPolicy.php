<?php

namespace Z77\Core\Http\Response;

/**
 * DeliveryPolicy
 *
 * What a browser may DO with a file this framework hands out (DMS-ACTIVE-001). An uploaded
 * `.txt` holding `<script>` sniffs `text/html`, an SVG or XHTML carries script of its own:
 * shown inline on the application's domain, that script runs with the session of whoever
 * opened the file. So the type is judged at output, by {@see FileResponse},
 * {@see BytesResponse} and {@see ByteSourceResponse}, and no caller can opt out. An allowlist: an unknown type is
 * protected by default. The bytes are never touched.
 *
 *   type                                    inline answer                 CSP
 *   raster image, text/plain, text/csv,
 *   application/json                        as is                         sandbox
 *   application/pdf, video/*, audio/*       as is                         none (inline only)
 *   image type containing `svg`             as is                         SVG_CSP
 *   other text/*, *\/xml, *+xml, JavaScript text/plain; charset=utf-8      sandbox
 *   everything else, empty, malformed       forced to attachment          sandbox
 *
 * `X-Content-Type-Options: nosniff` on every answer. A download of an active type keeps its
 * type: attachment, and the sandbox if a browser renders it all the same. The active check
 * comes before `video/*` and `audio/*`: a browser renders any `*+xml` as a document.
 *
 * These headers go out before the status is decided, so 200, 206, 304 and 416 all carry them.
 */
final class DeliveryPolicy
{
    /** A sandboxed document without script; inline styles and data images keep a drawing intact. */
    public const SVG_CSP = "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'";

    private const SANDBOX  = 'sandbox';
    private const FALLBACK = 'application/octet-stream';
    private const ESSENCE  = '#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#D';
    private const CONTROL  = '/[\x00-\x1f\x7f]/';

    private const RASTER = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
        'image/x-icon', 'image/vnd.microsoft.icon', 'image/tiff',
    ];
    private const SCRIPT = ['application/javascript', 'application/ecmascript', 'application/x-javascript'];
    private const TEXT   = ['text/plain', 'text/csv', 'application/json'];

    private const CLASS_SVG    = 'svg';
    private const CLASS_RASTER = 'raster';
    private const CLASS_TEXT   = 'text';
    private const CLASS_ACTIVE = 'active';
    private const CLASS_MEDIA  = 'media';
    private const CLASS_OTHER  = 'other';

    /**
     * Sends Content-Type, Content-Disposition, nosniff and the CSP for a file. Status,
     * length and caching stay with the response.
     */
    public static function apply(string $mime, bool $inline, string $filename): void
    {
        $essence = self::essence($mime);
        $class   = self::classify($essence);
        $inline  = $inline && $class !== self::CLASS_OTHER;

        // A control character makes header() refuse the whole line: PHP would then answer with
        // its default type, text/html, and an attachment without its disposition would be inline.
        $type = match (true) {
            $inline && $class === self::CLASS_ACTIVE                  => 'text/plain; charset=utf-8',
            self::isMalformed($mime), self::hasControlChar($mime)     => $essence,
            default                                                   => trim($mime),
        };
        $name = (string) preg_replace(self::CONTROL, '', $filename);

        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($name) . '"');
        header('X-Content-Type-Options: nosniff');

        $csp = match (true) {
            $class === self::CLASS_SVG              => self::SVG_CSP,
            $inline && $class === self::CLASS_MEDIA => null,
            default                                 => self::SANDBOX,
        };
        if ($csp !== null) {
            header('Content-Security-Policy: ' . $csp);
        }
    }

    /**
     * Whether a type is shown as is and is not text: raster image, video, audio, PDF. The
     * only types the DMS may leave to the web server as a static copy.
     */
    public static function isInlineBinary(string $mime): bool
    {
        return in_array(self::classify(self::essence($mime)), [self::CLASS_RASTER, self::CLASS_MEDIA], true);
    }

    /** Lower-case type without parameters; empty or malformed means the binary fallback. */
    private static function essence(string $mime): string
    {
        return self::isMalformed($mime) ? self::FALLBACK : strtolower(trim(explode(';', $mime, 2)[0]));
    }

    private static function isMalformed(string $mime): bool
    {
        return preg_match(self::ESSENCE, strtolower(trim(explode(';', $mime, 2)[0]))) !== 1;
    }

    private static function hasControlChar(string $value): bool
    {
        return preg_match(self::CONTROL, $value) === 1;
    }

    private static function classify(string $essence): string
    {
        return match (true) {
            str_starts_with($essence, 'image/') && str_contains($essence, 'svg') => self::CLASS_SVG,
            in_array($essence, self::RASTER, true)                               => self::CLASS_RASTER,
            in_array($essence, self::TEXT, true)                                 => self::CLASS_TEXT,
            str_starts_with($essence, 'text/'),
            str_ends_with($essence, '/xml'),
            str_ends_with($essence, '+xml'),
            in_array($essence, self::SCRIPT, true)                               => self::CLASS_ACTIVE,
            $essence === 'application/pdf',
            str_starts_with($essence, 'video/'),
            str_starts_with($essence, 'audio/')                                  => self::CLASS_MEDIA,
            default                                                              => self::CLASS_OTHER,
        };
    }
}
