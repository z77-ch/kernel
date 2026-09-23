<?php

namespace Z77\Shared\Stats;

/**
 * The daily salt behind the visitor key — one random value per calendar day,
 * kept in `var/lib/stats/salt`, replaced the first time a request arrives on
 * a new day.
 *
 * Why `var/lib` (ADR-035): the salt may be deleted at any moment (the cost is
 * one day's visitors counted twice, nothing else), so it belongs under `var/`;
 * and it must be the SAME on both doors of a release layout and survive a
 * release switch within the day, or the switch would split every visitor in
 * two — so it is the shared branch `var/lib`, not the release-local
 * `var/cache` / `var/state`. Never `data/` (it is not a record) and never
 * `logs/` (it would ride into the backup next to the lines it protects).
 *
 * The salt is never logged, never shown, never reused: yesterday's value is
 * overwritten, which is what makes yesterday's keys unlinkable to today's.
 *
 * ⚠️ Nothing here throws. An unwritable directory answers null, and the
 * recorder then writes the line without a visitor key rather than not at all.
 */
final class StatsSalt
{
    /** Relative to ABS_BASE_PATH. */
    public const DIR = 'var/lib/stats';

    public const FILE = 'salt';

    /** Memoised per process: one read per request. */
    private static ?string $day = null;
    private static ?string $salt = null;

    /**
     * The salt for $day (default: today, application timezone), creating or
     * rotating the file when needed. Null when the file can neither be read
     * nor written.
     */
    public static function forDay(?string $day = null): ?string
    {
        $day ??= date('Y-m-d');
        if (self::$day === $day) {
            return self::$salt;
        }

        try {
            $salt = self::read($day) ?? self::rotate($day);
        } catch (\Throwable) {
            $salt = null;
        }

        self::$day  = $day;
        self::$salt = $salt;

        return $salt;
    }

    /** Drop the memo — tests, and anything that changes the day under a running process. */
    public static function forget(): void
    {
        self::$day  = null;
        self::$salt = null;
    }

    public static function file(): ?string
    {
        if (!defined('ABS_BASE_PATH')) {
            return null;
        }

        return ABS_BASE_PATH . '/' . self::DIR . '/' . self::FILE;
    }

    /** The stored salt when the file is for $day, else null. */
    private static function read(string $day): ?string
    {
        $file = self::file();
        if ($file === null || !is_file($file)) {
            return null;
        }
        $content = @file_get_contents($file);
        if (!is_string($content) || preg_match('~^(\d{4}-\d{2}-\d{2}) ([0-9a-f]{64})\s*$~', $content, $m) !== 1) {
            return null;
        }

        return $m[1] === $day ? $m[2] : null;
    }

    /**
     * Writes a fresh salt for $day — temp file + rename, so a reader never
     * sees a half-written file — and re-reads it: two requests rotating at
     * the same moment both end up with whichever rename won, not each with
     * its own value.
     */
    private static function rotate(string $day): ?string
    {
        $file = self::file();
        if ($file === null) {
            return null;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $fresh = bin2hex(random_bytes(32));
        $tmp   = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $day . ' ' . $fresh . "\n") === false) {
            return null;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            // The other writer may have won the race — its value is as good as ours.
            return self::read($day);
        }

        return self::read($day) ?? $fresh;
    }
}
