<?php
namespace Z77\Core\Exception;

use Z77\Core\DI;
use Z77\Core\Http\RequestMode;

class ExceptionHandler
{
    /**
     * Zentraler Exception-Handler.
     *
     * Format-Auswahl:
     *   - 'html' / 'json'     → expliziter Override
     *   - 'auto' (default)    → Request-Mode bestimmt: Fetch → json, Page → html
     */
    public static function handle(\Throwable $e, string $format = 'auto'): void
    {
        $statusCode = 500;

        if (
            ($e instanceof NotFoundException) ||
            ($e instanceof FileNotFoundException)
        ) {
            $statusCode = 404;
        } elseif ($e instanceof InvalidRouteException) {
            $statusCode = 400;
        }

        http_response_code($statusCode);

        if ($e instanceof FileNotFoundException) {
            header('Content-Type: text/html; charset=utf-8');
            echo 'Error 404: File Not Found';
            exit;
        }

        if ($format === 'auto') {
            $format = self::resolveFormatFromRequest();
        }

        $errorMessage = $e->getMessage();

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'   => $errorMessage,
                'code'    => $statusCode,
                'details' => ini_get('display_errors') ? $e->getTrace() : null,
            ], JSON_PRETTY_PRINT);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo "<h1>{$statusCode}</h1>";
            echo "<p>" . htmlspecialchars($errorMessage) . "</p>";

            if (ini_get('display_errors')) {
                echo "<pre>" . $e->getTraceAsString() . "</pre>";
            }
        }

        exit;
    }

    /**
     * Determines render format from RequestMode. Falls back to 'html' if the
     * request is not yet available (very early bootstrap errors).
     */
    private static function resolveFormatFromRequest(): string
    {
        try {
            $request = DI::getRequest();
            return $request->getMode() === RequestMode::Fetch ? 'json' : 'html';
        } catch (\Throwable) {
            return 'html';
        }
    }
}
