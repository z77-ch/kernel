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

        // Stateless route (e.g. /api): ALWAYS the API error envelope — JSON,
        // no-store, never a trace, never HTML (api-envelope-v1). Checked before
        // the FileNotFound short-circuit so /api/…/x.json cannot answer HTML.
        if (self::isStatelessRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['error' => [
                'code'    => $statusCode === 404 ? 'unknown_endpoint' : 'internal',
                'message' => $statusCode >= 500 ? 'Internal error.' : $e->getMessage(),
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }

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
     * Last resort for a Throwable nobody caught — registered by Bootstrap as
     * the process exception handler, so it covers the whole request, early
     * boot included.
     *
     * Why it exists: PHP answers an uncaught error with 500 only while
     * `display_errors` is off and no user handler took the error. In DEBUG
     * both are the other way round, so a fatal went out as HTTP 200 (P2 exit
     * check 2026-09-22: the first setup page died with status 200). The
     * status is set here, in one place, before anything is printed.
     *
     * `display_errors` off (production): the half-built page is dropped, the
     * error is logged, the client gets a generic 500 without internals.
     * `display_errors` on: message and trace, as PHP itself would show them.
     * A stateless route (/api) keeps its JSON envelope (handle()).
     */
    public static function handleUncaught(\Throwable $e): void
    {
        self::markFailed();
        error_log('Uncaught ' . $e);

        if (self::isStatelessRequest()) {
            self::handle($e);   // JSON envelope, never a trace; exits
        }

        $display = (bool)ini_get('display_errors');
        if (!$display) {
            // Drop what the request rendered so far — a half page must not go
            // out ahead of the error. Kept when displaying: in DEBUG that output
            // may be the developer's own debug() calls.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        if (self::resolveFormatFromRequest() === 'json') {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'error' => $display ? $e->getMessage() : 'Internal error.',
                'code'  => 500,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<h1>500</h1>';
        if ($display) {
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
    }

    /**
     * Shutdown check for a real fatal (E_ERROR, parse/compile errors) — those
     * bypass every exception handler. Registered by Bootstrap next to
     * handleUncaught(); best effort: once PHP has printed the message, the
     * headers may already be out.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        self::markFailed();

        // `display_errors` off: PHP printed nothing, so the body would be the
        // half-rendered page (or empty). Same generic body as handleUncaught().
        if (!ini_get('display_errors') && !headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>500</h1>';
        }
    }

    /**
     * Sets 500 on a response whose headers are still open — the one place the
     * «request failed» status is set for uncaught errors. Also called by the
     * DEBUG handlers (setOwnExceptionHandler), which render their own box.
     */
    public static function markFailed(): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }
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

    /** False when the request is not available yet (very early bootstrap errors). */
    private static function isStatelessRequest(): bool
    {
        try {
            return DI::getRequest()->isStateless();
        } catch (\Throwable) {
            return false;
        }
    }
}
