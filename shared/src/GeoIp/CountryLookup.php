<?php

namespace Z77\Shared\GeoIp;

/**
 * "Which country is this IP in?" — the one seam the rest of the framework
 * uses. Consumers ask THIS class, never {@see MmdbReader} directly: the
 * reader is the file format, this is the question.
 *
 * The answer is a two-letter ISO code or null. Null is a normal answer, not a
 * failure: a private address, an unassigned range, a database that was never
 * installed, or a file that turned out to be broken all mean the same thing
 * to a caller — «we do not know». ⚠️ NOTHING here throws. This sits in the
 * path of a public registration form; a missing optional database must never
 * be the reason a customer cannot sign up.
 *
 * Installation: drop MaxMind's GeoLite2-Country.mmdb into
 * `data/framework/geoip/`. Any `*.mmdb` in that folder is taken, so the file
 * may keep whatever name the vendor's archive gave it.
 *
 * ⚠️ Licence (GeoLite EULA): the database must not be redistributed — it
 * belongs to the INSTALLATION, never to the repository or a package — it must
 * be kept current, and {@see ATTRIBUTION} must be visible wherever its
 * results are shown. It must not be used to identify a person, household or
 * street address; country level is all this class will ever answer.
 *
 * The caller passes REMOTE_ADDR. ⚠️ Never X-Forwarded-For or Client-IP:
 * those are claims by the client, and a country check built on them is
 * bypassed by one header (the mistake this replaces did exactly that).
 */
final class CountryLookup
{
    /** Relative to ABS_BASE_PATH. */
    public const DIR = 'data/framework/geoip';

    /** Required by the GeoLite EULA wherever results are displayed. */
    public const ATTRIBUTION = 'This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com';

    private static bool $looked = false;
    private static ?MmdbReader $reader = null;
    private static ?string $file = null;

    /** ISO 3166-1 alpha-2, or null when unknown. */
    public static function of(?string $ip): ?string
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return null;
        }

        $reader = self::reader();
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable) {
            // A truncated or corrupt database is an installation fault. It must
            // be visible in the log as "unknown", not as a 500 on the form.
            return null;
        }

        // `country` is where the IP is used; `registered_country` where the
        // block is registered. Prefer the first and fall back — a hosting
        // range often carries only the second, and for abuse control the
        // registration is a better answer than nothing.
        $iso = $record['country']['iso_code']
            ?? $record['registered_country']['iso_code']
            ?? null;

        return is_string($iso) && preg_match('/^[A-Z]{2}$/', $iso) ? $iso : null;
    }

    /** True when a database is installed and readable. */
    public static function available(): bool
    {
        return self::reader() !== null;
    }

    /** Absolute path of the database in use, or null. */
    public static function databaseFile(): ?string
    {
        self::reader();

        return self::$file;
    }

    /**
     * When MaxMind built the installed database (unix time), or null.
     * The update job compares against this; the backend shows it, so an
     * operator can see at a glance that the data has not gone stale.
     */
    public static function databaseBuiltAt(): ?int
    {
        $reader = self::reader();
        if ($reader === null) {
            return null;
        }

        $epoch = $reader->metadata()['build_epoch'] ?? null;

        return is_int($epoch) && $epoch > 0 ? $epoch : null;
    }

    /** Drop the memoised reader — tests, and after the update job swaps the file. */
    public static function forget(): void
    {
        self::$looked = false;
        self::$reader = null;
        self::$file   = null;
    }

    /** Point the lookup at a specific file — tests only. */
    public static function useFile(string $file): void
    {
        self::forget();
        self::$looked = true;
        self::$file   = $file;

        try {
            self::$reader = new MmdbReader($file);
        } catch (\Throwable) {
            self::$reader = null;
            self::$file   = null;
        }
    }

    private static function reader(): ?MmdbReader
    {
        if (self::$looked) {
            return self::$reader;
        }
        self::$looked = true;

        if (!defined('ABS_BASE_PATH')) {
            return null;
        }

        // Any *.mmdb, so the vendor's own file name survives an update. Sorted
        // for a deterministic pick if someone leaves two files behind.
        $candidates = glob(ABS_BASE_PATH . '/' . self::DIR . '/*.mmdb') ?: [];
        sort($candidates);

        foreach ($candidates as $candidate) {
            try {
                self::$reader = new MmdbReader($candidate);
                self::$file   = $candidate;

                return self::$reader;
            } catch (\Throwable) {
                continue; // try the next file rather than dying on a bad one
            }
        }

        return null;
    }
}
