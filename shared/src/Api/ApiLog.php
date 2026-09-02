<?php

namespace Z77\Shared\Api;

use Z77\Core\DI;

/**
 * One line per API request — `logs/api.log` (api-envelope-v1). The GeoGuard
 * logging philosophy: a stretch of failures must be visible, not silent.
 * Two writers share the format: the ApiKeyGuard (401 denials — tenant `-`)
 * and the api module's gateway (everything past the guard).
 *
 * Format (grep-friendly, space-separated); the principal column is the
 * tenant, or `tenant:keyRef` when the resolver names the connection:
 *
 *   2026-09-02T14:03:11+02:00 8 GET /api/v1/units?lang=fr 200 12.3ms
 *   2026-09-02T14:03:12+02:00 8:widget-a GET /api/v1/units 200 4.1ms
 *
 * A failing log write must not fail the request: it falls back to
 * error_log() (php-error.log) instead of throwing.
 *
 * Rotation is built in (config-split handoff, point 3): past ~5 MB the file
 * is renamed to `api.log.1` (replacing the previous generation) and a fresh
 * one starts — API-002 wants a stretch of failures READ, so the file must
 * stay a readable size, and unrotated it only ever grows. One generation
 * (~10 MB cap) is deliberate: this is an operator log, not an archive.
 */
final class ApiLog
{
    private const ROTATE_BYTES = 5_000_000;

    /** @param string|null $principal `tenant` or `tenant:keyRef`; null on a 401 (renders `-`) */
    public static function line(?string $principal, int $status, ?float $durationMs = null): void
    {
        try {
            $request = DI::getRequest();
            $entry = sprintf(
                "%s %s %s %s %d%s\n",
                date('c'),
                $principal ?? '-',
                strtoupper($request->getMethod()),
                $request->getRawRequestUri(),
                $status,
                $durationMs === null ? '' : sprintf(' %.1fms', $durationMs)
            );

            $dir = rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/logs';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("api.log directory missing: {$dir}");
            }

            $file = $dir . '/api.log';
            if (is_file($file) && filesize($file) >= self::ROTATE_BYTES) {
                @unlink($file . '.1'); // Windows rename does not overwrite
                @rename($file, $file . '.1');
            }
            file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('api.log write failed: ' . $e->getMessage());
        }
    }
}
