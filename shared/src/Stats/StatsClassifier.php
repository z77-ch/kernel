<?php

namespace Z77\Shared\Stats;

/**
 * Turns request facts into the coarse classes the statistic stores — and
 * nothing finer. Pure functions over strings; no request, no file, no DI,
 * so the harness can pin every rule without a web server.
 *
 * Everything here runs AT REQUEST TIME, deliberately: the line never carries
 * the user agent, the referer or the address, so what is not classified now
 * cannot be derived later. That is the privacy design, not a limitation.
 *
 * The classes are coarse on purpose (mobile / tablet / desktop, a browser
 * family, a referer class plus host). A statistic that answers «how many
 * came from LinkedIn on a phone» needs no version numbers, and every extra
 * detail is one more thing that could single out a person.
 */
final class StatsClassifier
{
    /** Query keys that survive into the stored path — campaign attribution only. */
    public const CAMPAIGN_KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];

    /** A campaign value longer than this is truncated (it is free text from a link). */
    public const CAMPAIGN_VALUE_MAX = 60;

    /** A referral host longer than this is truncated. */
    public const HOST_MAX = 80;

    /**
     * Known crawlers, monitors and scripted clients by user agent — a
     * blocklist of tokens, not an allowlist of browsers: an unknown browser
     * counts, an unknown bot that names itself «bot» does not. An EMPTY user
     * agent is a bot too (no browser sends none).
     */
    public const BOT_PATTERN = '~bot|crawl|spider|slurp|curl/|wget/|python|httpclient|java/|libwww|okhttp|go-http-client|headless|phantom|lighthouse|pingdom|uptime|monitor|facebookexternalhit|whatsapp|telegram|preview|scan|fetch|feed|archive|semrush|ahrefs|mj12|dataprovider|petal|bytespider|gpt|claude|anthropic|openai|ccbot|applebot|yandex|baidu|duckduck~i';

    /** Referring hosts classified as `search` (the host itself is kept too). */
    private const SEARCH_ENGINES = [
        '~(^|\.)google\.[a-z.]+$~',
        '~(^|\.)bing\.com$~',
        '~(^|\.)duckduckgo\.com$~',
        '~(^|\.)yahoo\.[a-z.]+$~',
        '~(^|\.)ecosia\.org$~',
        '~(^|\.)qwant\.com$~',
        '~(^|\.)startpage\.com$~',
        '~^search\.brave\.com$~',
        '~(^|\.)yandex\.[a-z.]+$~',
        '~(^|\.)baidu\.com$~',
        '~(^|\.)seznam\.cz$~',
    ];

    /** Referring hosts classified as `social`. */
    private const SOCIAL_NETWORKS = [
        '~(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$~',
        '~(^|\.)instagram\.com$~',
        '~(^|\.)(linkedin\.com|lnkd\.in)$~',
        '~(^|\.)(twitter\.com|x\.com|t\.co)$~',
        '~(^|\.)(youtube\.com|youtu\.be)$~',
        '~(^|\.)pinterest\.[a-z.]+$~',
        '~(^|\.)tiktok\.com$~',
        '~(^|\.)reddit\.com$~',
        '~(^|\.)threads\.net$~',
        '~(^|\.)(whatsapp\.com|wa\.me)$~',
        '~(^|\.)(telegram\.org|t\.me)$~',
        '~(^|\.)xing\.com$~',
    ];

    public static function isBot(?string $userAgent): bool
    {
        $ua = trim((string)$userAgent);

        return $ua === '' || preg_match(self::BOT_PATTERN, $ua) === 1;
    }

    /**
     * `{class}/{browser}` — e.g. `mobile/safari`, `desktop/chrome`. One field,
     * split by the rollup into a device tally and a browser tally.
     *
     * Known coarse edge: an iPad on iPadOS 13+ announces itself as a Mac and
     * lands in `desktop`; a statistic at this level lives with that.
     */
    public static function device(?string $userAgent): string
    {
        $ua = (string)$userAgent;

        if (preg_match('~iPad|Tablet|PlayBook|Silk|Kindle~i', $ua) === 1
            || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile'))) {
            $class = 'tablet';
        } elseif (preg_match('~Mobile|iPhone|iPod|Android|Windows Phone|Opera Mini|IEMobile|BlackBerry|webOS~i', $ua) === 1) {
            $class = 'mobile';
        } else {
            $class = 'desktop';
        }

        return $class . '/' . self::browser($ua);
    }

    /** Browser family, lowercase; `other` when nothing is recognised. Order matters. */
    public static function browser(string $userAgent): string
    {
        return match (true) {
            preg_match('~\bEdg(e|A|iOS)?/~', $userAgent) === 1          => 'edge',
            preg_match('~\bOPR/|\bOpera\b~', $userAgent) === 1           => 'opera',
            str_contains($userAgent, 'SamsungBrowser')                   => 'samsung',
            preg_match('~\bFirefox/|\bFxiOS/~', $userAgent) === 1        => 'firefox',
            preg_match('~\bCriOS/|\bChrome/|\bChromium/~', $userAgent) === 1 => 'chrome',
            preg_match('~\bVersion/[\d.]+.*\bSafari/~', $userAgent) === 1 => 'safari',
            preg_match('~\bMSIE\b|\bTrident/~', $userAgent) === 1        => 'ie',
            default                                                      => 'other',
        };
    }

    /**
     * Where the visit came from — `{class}` or `{class}:{host}`:
     *
     *   campaign:{utm_source}   a link that carries utm_* (wins over the referer)
     *   direct                  no referer, or one without a host
     *   internal                the site itself (navigation within the site)
     *   search:{host}           a known search engine
     *   social:{host}           a known social network
     *   referral:{host}         any other site
     *
     * The HOST is kept, not only the class (orchestrator decision 2026-09-23):
     * lower-case, `www.` and port stripped, never the path or the query of
     * the referring URL. The rollup tallies the class and the host apart
     * ({@see StatsRollup}); the report groups by class and lists the hosts.
     * ⚠️ The host is the one thing in the statistic that names an outside
     * party — the privacy paragraph in stats.md says so; keep it true.
     *
     * @param array<string, mixed> $query the raw query map
     */
    public static function source(?string $referer, string $ownHost, array $query): string
    {
        $campaign = $query['utm_source'] ?? $query['utm_campaign'] ?? null;
        if (is_string($campaign) && trim($campaign) !== '') {
            return 'campaign:' . self::slug($campaign, self::CAMPAIGN_VALUE_MAX);
        }

        $host = self::hostOf($referer);
        if ($host === '') {
            return 'direct';
        }
        if ($host === self::bareHost($ownHost)) {
            return 'internal';
        }
        // A referer whose host does not look like a hostname (`a"b_c.example`,
        // an IP literal) is a referral from nowhere nameable — the class is
        // kept, the "host" is not (review B9, 2026-09-23).
        if (!self::isHostname($host)) {
            return 'referral';
        }

        return self::sourceClass($host) . ':' . mb_substr($host, 0, self::HOST_MAX);
    }

    /**
     * RFC 1123 shape — labels of letters, digits and hyphens (1–63 each, no
     * leading or trailing hyphen), joined by dots, at most 253 characters — and
     * not a dotted-quad IPv4 literal. IDNs arrive as punycode (`xn--…`) and
     * pass; an IPv6 literal keeps its brackets and colons and does not.
     */
    public static function isHostname(string $host): bool
    {
        return preg_match('~^(?=.{1,253}$)(?!\d+(?:\.\d+){3}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$~', $host) === 1;
    }

    /** `search`, `social` or `referral` for an external referring host. */
    public static function sourceClass(string $host): string
    {
        foreach (self::SEARCH_ENGINES as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return 'search';
            }
        }
        foreach (self::SOCIAL_NETWORKS as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return 'social';
            }
        }

        return 'referral';
    }

    /**
     * Splits a stored source into its class and its host (null for direct,
     * internal and campaign). `search:google.ch` → `['search', 'google.ch']`.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitSource(string $source): array
    {
        $pos = strpos($source, ':');
        if ($pos === false) {
            return [$source, null];
        }
        $class = substr($source, 0, $pos);
        $rest  = substr($source, $pos + 1);

        return in_array($class, ['search', 'social', 'referral'], true) && $rest !== ''
            ? [$class, $rest]
            : [$class, null];
    }

    /**
     * The whitelisted campaign keys as a stable query string (`utm_campaign=x&utm_source=y`,
     * sorted by key, values trimmed), or '' when the request carries none.
     * Everything else in the query is dropped — an id, a token, a search term
     * would otherwise end up in the path column.
     *
     * @param array<string, mixed> $query
     */
    public static function campaignQuery(array $query): string
    {
        $kept = [];
        foreach (self::CAMPAIGN_KEYS as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $kept[$key] = self::slug($value, self::CAMPAIGN_VALUE_MAX);
            }
        }
        ksort($kept);

        return http_build_query($kept);
    }

    /**
     * The daily visitor key: sha256 over address, user agent and the day's
     * salt, truncated to 16 hex characters. Null when any part is missing —
     * a line without a key is counted as a view but never as a visitor.
     *
     * The salt is what makes the key non-reversible AND non-linkable across
     * days: without it a table of «sha256(ip + ua)» could be built for the
     * known address space; with a salt that is thrown away every day, it
     * cannot — and the same person tomorrow is a different key.
     */
    public static function visitor(?string $ip, ?string $userAgent, ?string $salt): ?string
    {
        if ($ip === null || $ip === '' || $salt === null || $salt === '') {
            return null;
        }

        return substr(hash('sha256', $ip . '|' . (string)$userAgent . '|' . $salt), 0, 16);
    }

    private static function hostOf(?string $referer): string
    {
        $referer = trim((string)$referer);
        if ($referer === '') {
            return '';
        }
        $host = parse_url($referer, PHP_URL_HOST);

        return is_string($host) ? self::bareHost($host) : '';
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('~:\d+$~', '', $host) ?? $host;

        return preg_replace('~^www\.~', '', $host) ?? $host;
    }

    /**
     * Free text from a link, reduced to something a tally can key on.
     *
     * A value with an `@` is an ADDRESS, not a campaign name (newsletter tools
     * that put the recipient into `utm_source`), and becomes `unknown` — the
     * one shape of personal data in a utm value that can be told apart
     * mechanically (review B8, 2026-09-23). An opaque recipient id cannot;
     * that stays a project rule (stats.md: never a recipient id in utm_*).
     */
    private static function slug(string $value, int $max): string
    {
        if (str_contains($value, '@')) {
            return 'unknown';
        }
        $value = strtolower(trim($value));
        $value = preg_replace('~[^a-z0-9._-]+~', '-', $value) ?? '';
        $value = trim($value, '-');

        return mb_substr($value === '' ? 'unknown' : $value, 0, $max);
    }
}
