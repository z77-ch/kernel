<?php

namespace Z77\Shared\Stats;

use Z77\Core\DI;
use Z77\Core\Http\Request;
use Z77\Core\Http\RequestMode;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Core\Http\Response\ResponseInterface;
use Z77\Shared\GeoIp\CountryLookup;
use Z77\Shared\Throttle\FileThrottle;

/**
 * Web statistics without a third party — one line per counted request
 * (`docs/03-development/web-stats-bauplan.md`, `docs/topics/stats.md`).
 *
 * Two doors:
 *
 *   observe()  the Dispatcher, once per request, AFTER the response went out
 *              and whatever produced it — a page-cache HIT is a real visit and
 *              is counted like a rendered page. The counter never sits inside
 *              the render path; that is the one trap of the whole feature.
 *   event()    the beacon endpoint (`/frontend/main/stats/event`) for what does
 *              not pass PHP: a materialised media file, an outbound link. It
 *              takes a DECLARED event name ({@see StatsEvents}) plus the
 *              current path — nothing else, and nothing else is stored. The
 *              path is put on the page view's route ({@see canonicalPath()})
 *              so the two doors meet on one key per page.
 *
 * What a line carries — and what it does not: `at`, `path`, `status`, `lang`,
 * `visitor`, `source`, `device`, `country`, `event`. No address, no user
 * agent, no referer, no cookie, no session id. Country, source and device
 * class are resolved HERE, at request time, and the address becomes a daily
 * salted hash ({@see StatsClassifier::visitor()}, {@see StatsSalt}) — so the
 * raw line cannot be turned back into a person, today or later. ⚠️ Do not
 * widen the record. The privacy paragraph in `docs/topics/stats.md` states
 * exactly this list; a new field needs that text changed first.
 *
 * ⚠️ Nothing here throws. This runs on every public page; a full disk, a
 * read-only `logs/`, a broken GeoIP file must cost a line, never the page —
 * the {@see \Z77\Shared\Forms\FormLog} rule. Every failure path returns
 * silently; observe() and event() additionally wrap everything in a catch.
 *
 * Storage: `logs/stats/stats-YYYY-MM-DD.jsonl`, one JSON object per line,
 * appended with LOCK_EX — daily files because the rollup ({@see StatsRollup})
 * folds a closed day into the month's aggregate and deletes the raw file
 * after {@see StatsRollup::RAW_RETENTION_DAYS}. Its OWN directory under
 * `logs/` (owner decision E2, 2026-09-23): the raw lines carry visitor keys
 * and live seven days, so they must not ride into a backup archive that
 * lives for months — `BackupService::FIXED_EXCLUDES` leaves `logs/stats`
 * out unconditionally, while `logs/` itself (the form log, a record) stays
 * in. The directory is created at runtime with the same deny `.htaccess`
 * the installer seeds (INST-DENY-001 covers only directories that exist at
 * install time).
 */
final class StatsRecorder
{
    /** Relative to ABS_BASE_PATH. Its own directory, so the backup can leave the raw lines out (E2). */
    public const DIR = 'logs/stats';

    /**
     * Where the raw files lived until 2026-09-23 — directly in `logs/`. The
     * rollup still reads this directory so files written before the move are
     * folded and swept like any other ({@see StatsRollup}, stats.md STATS-008);
     * the recorder never writes here again.
     */
    public const LEGACY_DIR = 'logs';

    /** The event name of a page view; reserved, a project cannot declare it. */
    public const PAGE_EVENT = 'page';

    /** A page carrying this attribute in its head is not counted. */
    public const OFF_MARK = 'data-stats="off"';

    /** How far into the document the mark is looked for (the `<html>`/`<body>` tag, not the text). */
    public const OFF_SCAN_BYTES = 4096;

    /** A path longer than this is truncated in the line (it is request input). Characters, not bytes. */
    public const PATH_MAX = 200;

    /**
     * Beacon lines one address (IPv6: one /64) may write per hour — the brake
     * of the event endpoint (owner decision E3, 2026-09-23). The visitor key
     * carries the user agent, so a client rotating its agent is a new key
     * every time and the rollup's per-key caps cannot see it; the address is
     * what a client cannot choose. High enough for an office behind one NAT
     * address (300 documents opened in an hour), low enough that a loop
     * costs the attacker a new address every 300 lines. Counted with the
     * shared {@see FileThrottle} under `var/lib/throttle/stats`.
     */
    public const BEACON_LIMIT_PER_HOUR = 300;

    /** Relative to ABS_BASE_PATH — disposable, shared across releases (ADR-035), like every throttle. */
    public const THROTTLE_DIR = 'var/lib/throttle/stats';

    /** The installer's deny template — the same bytes the installer seeds into `data/`, `config/`, `logs/`. */
    private const DENY_TEMPLATE = __DIR__ . '/../../../core/res/htaccess-deny';

    /**
     * The Dispatcher's door — decides whether THIS request is a page view
     * worth a line, then records it. Not counted: anything but a GET in Page
     * mode, a session of role >= EDITOR (`$privileged`), a module that is not
     * `public` (the backend), a response that is not a page (a redirect, a
     * JSON answer, a file), a known bot, and a page marked `data-stats="off"`.
     * Assets never reach PHP; a stateless route (/api) never calls this.
     */
    public static function observe(Request $request, ResponseInterface $response, bool $privileged): void
    {
        try {
            if ($privileged
                || !$request->isGet()
                || $request->getMode() !== RequestMode::Page
                || !$response instanceof HtmlResponse
                || !self::isPublicModule($request->getModule())
            ) {
                return;
            }

            $userAgent = $request->getUserAgent();
            if (StatsClassifier::isBot($userAgent)) {
                return;
            }

            // Memoised by the response: on a fresh render this is the string
            // that just went out, on a cache HIT the stored body, on a 304 ''.
            if (self::markedOff($response->getHtml())) {
                return;
            }

            $status = http_response_code();

            self::record(new StatsHit(
                path:      $request->getPathWithoutLanguage(),
                query:     $request->getQueryParameters(),
                status:    is_int($status) ? $status : 200,
                lang:      $request->getLanguage(),
                ip:        $request->getClientIp(),
                userAgent: $userAgent,
                referer:   $request->getReferer(),
                host:      $request->getHost(),
            ));
        } catch (\Throwable) {
            // See the class docblock: a statistic is never worth a failed page.
        }
    }

    /**
     * The beacon's door. `$name` must be declared ({@see StatsEvents} — the
     * caller checks, so that a broken declaration fails loudly there rather
     * than silently here); `$path` is the page the browser reports, reduced
     * to a path ({@see cleanPath()}) and put on the page view's route
     * ({@see canonicalPath()}). The address is throttled first
     * ({@see beaconAllowed()}).
     *
     * @return bool whether a line was written — for the harness; the endpoint
     *              answers 204 either way (nothing to learn from the answer)
     */
    public static function event(string $name, string $path, Request $request, bool $privileged): bool
    {
        try {
            if ($privileged || !StatsEvents::isDeclared($name)) {
                return false;
            }
            $userAgent = $request->getUserAgent();
            if (StatsClassifier::isBot($userAgent)) {
                return false;
            }
            if (!self::beaconAllowed($request->getClientIp())) {
                return false;
            }

            return self::record(new StatsHit(
                path:      self::canonicalPath(self::cleanPath($path)),
                query:     [],
                status:    204,
                lang:      $request->getLanguage(),
                ip:        $request->getClientIp(),
                userAgent: $userAgent,
                referer:   null,
                host:      $request->getHost(),
                event:     $name,
            ));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The beacon brake: at most {@see BEACON_LIMIT_PER_HOUR} lines per address
     * (IPv6 per /64, {@see FileThrottle::normalizeIp()}) and hour. Counts the
     * attempt. Never throws, and fails OPEN: an unusable `var/lib` costs the
     * brake, not the count — and it is the tree the salt lives in too, so
     * those lines carry no visitor key, fall into the rollup's `-` bucket and
     * meet {@see StatsRollup::EVENT_DAY_CAP} there. Public for the harness.
     */
    public static function beaconAllowed(?string $ip): bool
    {
        $ip = FileThrottle::normalizeIp((string)$ip);
        if ($ip === null || !defined('ABS_BASE_PATH')) {
            return true;
        }

        try {
            return (new FileThrottle(ABS_BASE_PATH . '/' . self::THROTTLE_DIR))
                ->allow('beacon:' . $ip, self::BEACON_LIMIT_PER_HOUR, 3600);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Classifies and appends one line. Public so the harness can drive it
     * without a Request; production code goes through the two doors above.
     *
     * @return bool whether the line was written
     */
    public static function record(StatsHit $hit): bool
    {
        try {
            $campaign = StatsClassifier::campaignQuery($hit->query);
            $path     = mb_substr($hit->path, 0, self::PATH_MAX) . ($campaign !== '' ? '?' . $campaign : '');

            $row = [
                'at'      => date('c'),
                'path'    => $path,
                'status'  => $hit->status,
                'lang'    => $hit->lang,
                'visitor' => StatsClassifier::visitor($hit->ip, $hit->userAgent, StatsSalt::forDay()),
                'source'  => StatsClassifier::source($hit->referer, $hit->host, $hit->query),
                'device'  => StatsClassifier::device($hit->userAgent),
                'country' => CountryLookup::of($hit->ip),
                'event'   => $hit->event,
            ];

            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return false;
            }

            $file = self::file();
            if ($file === null) {
                return false;
            }

            return @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Today's `logs/stats/stats-YYYY-MM-DD.jsonl`, or null when the directory is unusable. */
    public static function file(): ?string
    {
        $dir = self::dir();
        if ($dir === null) {
            return null;
        }

        return $dir . '/stats-' . date('Y-m-d') . '.jsonl';
    }

    /**
     * The raw directory, created on first use. A directory born here gets the
     * deny `.htaccess` at once — and so does `logs/` when it has none yet
     * (created by the same mkdir, or earlier by the form log without the
     * installer seeing it). Only on creation, so the hot path pays no stat
     * for it; a failure to seed costs the file, never the line.
     */
    public static function dir(): ?string
    {
        if (!defined('ABS_BASE_PATH')) {
            return null;
        }

        $dir = ABS_BASE_PATH . '/' . self::DIR;
        if (is_dir($dir)) {
            return $dir;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        self::seedDeny($dir);
        self::seedDeny(dirname($dir));

        return $dir;
    }

    /** True when the document's head carries `data-stats="off"` (on `<html>` or `<body>`). */
    public static function markedOff(string $html): bool
    {
        return str_contains(substr($html, 0, self::OFF_SCAN_BYTES), self::OFF_MARK);
    }

    /**
     * A beacon-reported path reduced to a path — spelled like the Request
     * spells a page view, so both land on one key (owner decision E4): query
     * and fragment cut, percent-encoding decoded ONCE (the Request decodes
     * `REQUEST_URI` the same way; `encodeURIComponent(location.pathname)`
     * arrives double-encoded and collapses here too), UTF-8 allowed (`/über-uns`
     * is a page), no control characters or spaces, no double or trailing
     * slash, at most PATH_MAX characters. What is not a path — empty, not
     * starting with `/`, `javascript:…` — becomes `/`: the event is a real
     * claim, the page just unknown; dropping it lost the event (E4 probe).
     */
    public static function cleanPath(string $path): string
    {
        $path = strtok(trim($path), '?#');
        $path = $path === false ? '' : rawurldecode($path);
        if ($path === '' || $path[0] !== '/' || !mb_check_encoding($path, 'UTF-8') || preg_match('~[\x00-\x20\x7F]~', $path) === 1) {
            return '/';
        }
        $path = rtrim(preg_replace('~/{2,}~', '/', $path) ?? $path, '/');

        return $path === '' ? '/' : mb_substr($path, 0, self::PATH_MAX);
    }

    /**
     * The cleaned beacon path on the page view's route: a language prefix is
     * stripped (`Request::extractLanguage()` does the same), an alias URL is
     * turned into its canonical form through the same {@see \Z77\Core\Routing\AliasPathResolver}
     * the Request uses (`/fr/contact` → `/kontakt`), a technical path stays as
     * spelled. Without the services (the harness, a process that never booted
     * routing) the cleaned path is the answer — never a throw, see the class
     * docblock.
     */
    private static function canonicalPath(string $path): string
    {
        try {
            $segments = $path === '/' ? [] : explode('/', substr($path, 1));
            $i18n     = DI::getI18n();
            $language = $i18n->getDefaultLanguage();
            if (isset($segments[0]) && preg_match('~^[a-z]{2}$~', $segments[0]) === 1 && $i18n->isValidLanguage($segments[0])) {
                $language = array_shift($segments);
            }
            if ($segments !== []) {
                $alias = DI::getAliasPathResolver()->resolve($segments, $language);
                if (is_array($alias) && is_array($alias['canonical'] ?? null)) {
                    $segments = $alias['canonical'];
                }
            }

            return '/' . implode('/', $segments);
        } catch (\Throwable) {
            return $path;
        }
    }

    private static function seedDeny(string $dir): void
    {
        $target = $dir . '/.htaccess';
        if (file_exists($target)) {
            return;
        }
        $template = @file_get_contents(self::DENY_TEMPLATE);
        if ($template !== false) {
            @file_put_contents($target, $template, LOCK_EX);
        }
    }

    private static function isPublicModule(string $module): bool
    {
        return DI::getModuleManager()->getModuleConfig($module)?->get('public', false) === true;
    }
}
