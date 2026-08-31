<?php

namespace Z77\Shared\Qr;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use BaconQrCode\Common\ErrorCorrectionLevel;

/**
 * QR code generation — the framework facade over the vendored bacon-qr-code
 * encoder (see kernel vendored/README.md). Consumers use THIS class, never
 * BaconQrCode directly: the facade is the seam that would survive swapping
 * the encoder.
 *
 * Two known consumers shape the API: the member module's TOTP setup (otpauth
 * URI → PNG data URI on the profile page, B8) and the Swiss QR-bill (ISO
 * 20022 payload at error correction level M with the Swiss cross overlaid —
 * the overlay lives with the invoicing code, on top of png()).
 *
 * Error correction levels: L (7%), M (15% — the QR-bill's mandated level),
 * Q (25%), H (30%).
 */
final class QrCode
{
    /** Binary PNG, square, $sizePx a side (GD, 4-module quiet zone). */
    public static function png(string $payload, int $sizePx = 240, string $ecc = 'M'): string
    {
        $renderer = new GDLibRenderer($sizePx, 4, 'png');

        return self::write($renderer, $payload, $ecc);
    }

    /** data:-URI PNG — drops straight into an <img src>. */
    public static function pngDataUri(string $payload, int $sizePx = 240, string $ecc = 'M'): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($payload, $sizePx, $ecc));
    }

    /** SVG markup, scales losslessly (print / PDF embedding). */
    public static function svg(string $payload, int $sizePx = 240, string $ecc = 'M'): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($sizePx, 4),
            new SvgImageBackEnd()
        );

        return self::write($renderer, $payload, $ecc);
    }

    private static function write(object $renderer, string $payload, string $ecc): string
    {
        if ($payload === '') {
            throw new \InvalidArgumentException('QR payload must not be empty');
        }

        $level = match (strtoupper($ecc)) {
            'L'     => ErrorCorrectionLevel::L(),
            'M'     => ErrorCorrectionLevel::M(),
            'Q'     => ErrorCorrectionLevel::Q(),
            'H'     => ErrorCorrectionLevel::H(),
            default => throw new \InvalidArgumentException("Unknown error correction level '{$ecc}' — use L, M, Q or H"),
        };

        return (new Writer($renderer))->writeString($payload, 'utf-8', $level);
    }
}
