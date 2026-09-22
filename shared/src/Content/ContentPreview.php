<?php

namespace Z77\Shared\Content;

/**
 * Content variants and their preview (ADR-044 addendum "Variants").
 *
 * A content document may exist in variants beside its live copy: same slug and
 * language, plus a variant key ('herbst-a7f3k2'). The live copy has the key ''.
 * All documents that share a key form one set (typically one writer delivery);
 * publishing the set turns each of them into the live copy.
 *
 * The preview is a plain URL parameter: `?preview=<key>` renders every document
 * in that variant where one exists and live everywhere else. It is carried from
 * page to page by localizedUrl() — the state is in the URL, nowhere else (no
 * cookie, no session). Visitors without the parameter always see live.
 *
 * The key is not a secret in the strict sense; the random tail only keeps a
 * preview URL from being guessed. Anything with a preview key is noindex and
 * never enters the page cache (PageCachePolicy).
 */
final class ContentPreview
{
    public const PARAM = 'preview';

    private const MAX_LENGTH = 64;

    private function __construct() {}

    /**
     * The canonical form of a variant key: lowercase, a-z 0-9 and '-', at most
     * 64 characters. Anything else is removed; '' means "no variant" (live).
     */
    public static function normalize(string $key): string
    {
        $key = preg_replace('/[^a-z0-9-]+/', '', strtolower(trim($key)));

        return substr(trim((string)$key, '-'), 0, self::MAX_LENGTH);
    }

    /**
     * A new variant key from a readable name: '<name>-<6 hex>'. The name part is
     * normalized and shortened; an empty name gives just 'satz-<6 hex>' (never close to a version key v-YYYYMMDD-HHMMSS).
     */
    public static function newKey(string $name): string
    {
        $name = substr(self::normalize($name), 0, 40);

        return ($name !== '' ? $name : 'satz') . '-' . bin2hex(random_bytes(3));
    }

    /**
     * The key a version gets when a live save archives the previous live copy
     * (ADR-045): 'v-YYYYMMDD-HHMMSS', the time of the archiving save; $n > 1
     * appends '-<n>' for a collision. A version is an ordinary one-document
     * variant — previewable and publishable like any other.
     */
    public static function versionKey(\DateTimeImmutable $at, int $n = 1): string
    {
        return 'v-' . $at->format('Ymd-His') . ($n > 1 ? '-' . $n : '');
    }

    /**
     * True for a key made by {@see versionKey()}. newKey() cannot produce one:
     * its tail is six hex characters, a collision suffix has at most three digits.
     */
    public static function isVersionKey(string $key): bool
    {
        return (bool)preg_match('/^v-\d{8}-\d{6}(-\d{1,3})?$/', $key);
    }

    /**
     * The preview key of the current request (?preview=), or null when there is
     * none. Read from the query string only — never from a cookie or the session.
     */
    public static function key(): ?string
    {
        $raw = $_GET[self::PARAM] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $key = self::normalize($raw);

        return $key !== '' ? $key : null;
    }

    /**
     * $url without the preview parameter — the same page as visitors see it
     * ("Live ansehen" in the preview bar). Other query parameters and a
     * #fragment are kept; a query left empty is dropped with its '?'.
     */
    public static function withoutPreview(string $url): string
    {
        $hashAt   = strpos($url, '#');
        $fragment = $hashAt === false ? '' : substr($url, $hashAt);
        $base     = $hashAt === false ? $url : substr($url, 0, $hashAt);

        $queryAt = strpos($base, '?');
        if ($queryAt === false) {
            return $url;
        }

        $path  = substr($base, 0, $queryAt);
        $pairs = array_filter(
            explode('&', substr($base, $queryAt + 1)),
            static fn(string $pair): bool => $pair !== ''
                && rawurldecode(explode('=', $pair, 2)[0]) !== self::PARAM
        );

        return ($path !== '' ? $path : '/') . ($pairs !== [] ? '?' . implode('&', $pairs) : '') . $fragment;
    }

    /**
     * $url with `preview=<key>` added to its query string — before a #fragment,
     * and not twice. $key null returns the URL unchanged.
     */
    public static function carry(string $url, ?string $key): string
    {
        if ($key === null || $key === '') {
            return $url;
        }

        $hashAt   = strpos($url, '#');
        $fragment = $hashAt === false ? '' : substr($url, $hashAt);
        $base     = $hashAt === false ? $url : substr($url, 0, $hashAt);

        $queryAt = strpos($base, '?');
        if ($queryAt !== false) {
            parse_str(substr($base, $queryAt + 1), $query);
            if (array_key_exists(self::PARAM, $query)) {
                return $url;
            }
        }

        $glue = $queryAt === false ? '?' : ($base[-1] === '?' || $base[-1] === '&' ? '' : '&');

        return $base . $glue . self::PARAM . '=' . rawurlencode($key) . $fragment;
    }
}
