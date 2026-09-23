<?php

namespace Z77\Shared\Stats;

/**
 * Folds the raw day files ({@see StatsRecorder}) into one aggregate per
 * month — `data/framework/stats/YYYY-MM.json` — and applies both retentions.
 * Pure over three directories, so the harness replays it against a temp tree;
 * {@see StatsRollupJob} is the cron wrapper.
 *
 * What the aggregate holds: per day (views, distinct visitors, visits,
 * events) and per month the totals of pages, entry pages, source classes,
 * referring hosts (by name, the {@see MAX_REFERRERS_PER_MONTH} most frequent),
 * devices, browsers, countries, languages, status codes, campaigns, events,
 * and events per page (`event_pages`, owner decision E4 2026-09-23 — «on
 * which page was the floor plan opened»). No line, no visitor key — the
 * aggregate carries nothing about a person, which is why it may stay for
 * {@see AGGREGATE_RETENTION_MONTHS} while a raw file lives
 * {@see RAW_RETENTION_DAYS}. The referring hosts are the one entry that
 * names an outside party; a project's privacy text has to say so.
 *
 * ⚠️ «Visitors per month» is the SUM of the daily distinct counts, not a
 * distinct count over the month: the visitor key is salted per day, so the
 * same person on two days is two keys — by design (nobody can be followed
 * across days), so a monthly unique does not exist. The report says
 * «Besucher (Tagessumme)», and this is the reason.
 *
 * Only CLOSED days are folded (day < today in the application timezone):
 * today's file is still being appended. A folded day is listed in the
 * month's `days_done`, so a rerun — a crash, a second cron line — never
 * counts a day twice. The raw file of a day is deleted only after its month
 * was saved; a month that could not be saved keeps its raw files for the
 * next run.
 *
 * ⚠️ Fails loudly, never quietly (review B5, 2026-09-23). A month file that
 * EXISTS but cannot be read or parsed blocks its month: no day of it is
 * folded, none of its raw files is deleted, the file is left in place for a
 * hand fix — overwriting it with a fresh month would have turned 5000 views
 * into one and reported «done». A day file that cannot be opened is not a
 * folded day: it is reported (`failedDays`), stays out of `days_done` and is
 * never swept. Both fail the job so the job log names them.
 *
 * Abuse caps ({@see PAGE_VIEW_DAY_CAP}, {@see EVENT_DAY_CAP}): the beacon
 * endpoint accepts any declared name from anyone, so one client could
 * inflate a month with a loop. Per visitor key and day only the first N page
 * views count, and per visitor key, day and event name only the first M
 * events; the rest are dropped and tallied under `capped`. Applied HERE and
 * not at request time, because the recorder must stay a blind append (no
 * read, no lock beyond the append) — and the numbers are above what a real
 * visitor reaches. {@see MAX_KEYS_PER_DAY} bounds the fold's MEMORY the same
 * way: the visitor key carries the user agent, so a client rotating its agent
 * is a new key per line and the per-key caps never trigger; without a ceiling
 * a flood day grows the key table until the job dies and the month is never
 * saved. Beyond the ceiling a line with a NEW key is dropped and tallied, and
 * the day is reported in `overflow` (the job note says so loudly).
 */
final class StatsRollup
{
    /** Raw day files older than this are deleted (after folding). */
    public const RAW_RETENTION_DAYS = 7;

    /**
     * Month aggregates are kept this many months, the running one included:
     * the file of month M is deleted by the first run in month M+24, so at any
     * time at most 24 month files exist. Owner default 2026-09-23. Decided by
     * the month in the NAME, never mtime (GEOIP-002).
     */
    public const AGGREGATE_RETENTION_MONTHS = 24;

    /**
     * Two caps, both per visitor key and day (orchestrator decision 2026-09-23):
     * a page view is something the server saw, a beacon event is something a
     * client claims — so the second is bounded tighter. Lines beyond a cap are
     * dropped and tallied under `capped`.
     */
    public const PAGE_VIEW_DAY_CAP = 100;

    /** Per visitor key, day AND event name. */
    public const EVENT_DAY_CAP = 20;

    /**
     * Distinct visitor keys the fold keeps in memory per day (owner decision E3,
     * 2026-09-23). Beyond it a line with a key not yet seen is dropped and
     * tallied under `capped`, and the day lands in the result's `overflow`.
     * Roughly 600 bytes per key → ~12 MB at the ceiling; a small site has a
     * few hundred keys a day, so reaching it means a flood — or a site this
     * statistic was never built for. Either way the operator must see it.
     */
    public const MAX_KEYS_PER_DAY = 20000;

    /**
     * A visit: consecutive page views of one visitor key with no gap longer
     * than this. Its ENTRY page is the path of its first page view. Computed
     * here from the raw lines — never reported by a beacon, which would count
     * a second time what the page view already records.
     */
    public const VISIT_GAP_SECONDS = 1800;

    /** Keys per tally while folding; beyond it new keys fold into {@see REST_KEY} (a spam guard). */
    public const MAX_KEYS_PER_TALLY = 500;

    /**
     * Referring hosts kept BY NAME per month (orchestrator decision 2026-09-23):
     * the most frequent ones, the rest summed into {@see REST_KEY} at save time,
     * so a link-spam wave cannot blow the file up. A host that once fell into
     * the rest stays there for its earlier count — an approximation, accepted.
     */
    public const MAX_REFERRERS_PER_MONTH = 50;

    /** Paths kept by name per event and month in `event_pages`; the rest → {@see REST_KEY}. Same character as the referrer cap. */
    public const MAX_PATHS_PER_EVENT = 50;

    /** The rest bucket of a capped tally. English like every key in the file; the report labels it. */
    public const REST_KEY = 'other';

    /** Relative to ABS_BASE_PATH. `data/` is shared across releases and in the backup. */
    public const AGGREGATE_DIR = 'data/framework/stats';

    /**
     * `sources` counts the CLASS (direct / internal / search / social / referral /
     * campaign); `referrers` counts the referring HOST — lower-case, no port,
     * no path, the one place the aggregate names an outside party, and kept
     * for the aggregate's 24 months (stats.md, privacy paragraph). A search
     * engine's host lands in `referrers` as well; `sources` is what the report
     * groups by. `campaigns` keeps the utm keys apart from the hosts.
     */
    private const TALLIES = ['pages', 'entries', 'sources', 'referrers', 'devices', 'browsers', 'countries', 'languages', 'status', 'campaigns', 'events'];

    /** The nested tally: event name → (path → count). Capped per event like `referrers`. */
    private const EVENT_PAGES = 'event_pages';

    /**
     * @param string      $rawDir       where the recorder writes (`logs/stats`)
     * @param string      $aggregateDir the month files
     * @param string|null $legacyRawDir where the recorder wrote until 2026-09-23 (`logs/`) — read and swept,
     *                                  never written; null when there is no transition to cover
     */
    public function __construct(
        private readonly string $rawDir,
        private readonly string $aggregateDir,
        private readonly ?string $legacyRawDir = null,
    ) {}

    public static function fromProject(): self
    {
        if (!defined('ABS_BASE_PATH')) {
            throw new \LogicException('StatsRollup needs ABS_BASE_PATH.');
        }

        return new self(
            ABS_BASE_PATH . '/' . StatsRecorder::DIR,
            ABS_BASE_PATH . '/' . self::AGGREGATE_DIR,
            ABS_BASE_PATH . '/' . StatsRecorder::LEGACY_DIR
        );
    }

    /**
     * One full pass: fold every closed, not yet folded day; save the touched
     * months; delete raw files past their retention (only for saved months,
     * never for a blocked month or a failed day); delete aggregates past theirs.
     *
     * @return array{folded: list<string>, lines: int, capped: int, overflow: array<string, int>, rawDeleted: int,
     *               aggregatesDeleted: int, failed: list<string>, failedDays: list<string>}
     *         `failed` names months whose file could not be read or saved, `failedDays` days whose
     *         raw file could not be read, `overflow` days that hit MAX_KEYS_PER_DAY → lines dropped
     */
    public function run(?int $now = null): array
    {
        $now   ??= time();
        $today   = date('Y-m-d', $now);
        $result  = ['folded' => [], 'lines' => 0, 'capped' => 0, 'overflow' => [], 'rawDeleted' => 0, 'aggregatesDeleted' => 0, 'failed' => [], 'failedDays' => []];
        $months  = [];
        $touched = [];
        $blocked = [];   // month → its file exists but is unreadable: hands off

        foreach ($this->rawFiles() as $day => $files) {
            if ($day >= $today) {
                continue;   // still open
            }
            $month = substr($day, 0, 7);
            if (isset($blocked[$month])) {
                continue;
            }
            if (!array_key_exists($month, $months)) {
                $loaded = $this->loadMonth($month);
                if ($loaded === null) {
                    $blocked[$month]    = true;
                    $result['failed'][] = $month;
                    continue;
                }
                $months[$month] = $loaded;
            }
            if (in_array($day, $months[$month]['days_done'], true)) {
                continue;
            }
            if (!$this->foldDay($files, $day, $months[$month], $result)) {
                $result['failedDays'][] = $day;
                continue;
            }
            $months[$month]['days_done'][] = $day;
            $touched[$month]   = true;
            $result['folded'][] = $day;
        }

        $saved = [];
        foreach (array_keys($touched) as $month) {
            if ($this->saveMonth($month, $months[$month])) {
                $saved[$month] = true;
            } else {
                $result['failed'][] = $month;
            }
        }

        // Raw retention. A closed day file is either folded in THIS run (its
        // month is `touched` — delete only when that month was saved) or was
        // folded by an earlier run (listed in days_done — safe to delete).
        // A blocked month and a failed day are left alone entirely.
        $rawCutoff = date('Y-m-d', $now - self::RAW_RETENTION_DAYS * 86400);
        foreach ($this->rawFiles() as $day => $files) {
            $month = substr($day, 0, 7);
            if ($day >= $rawCutoff || isset($blocked[$month]) || in_array($day, $result['failedDays'], true)) {
                continue;
            }
            if (isset($touched[$month]) && !isset($saved[$month])) {
                continue;
            }
            foreach ($files as $file) {
                if (@unlink($file)) {
                    $result['rawDeleted']++;
                }
            }
        }

        // Aggregate retention — by the month in the NAME, never mtime (GEOIP-002).
        // `<=`: the month exactly AGGREGATE_RETENTION_MONTHS back goes too, so
        // the running month plus 23 before it remain — 24 files, as promised.
        $monthCutoff = (new \DateTimeImmutable('@' . $now))->modify('-' . self::AGGREGATE_RETENTION_MONTHS . ' months')->format('Y-m');
        foreach (glob($this->aggregateDir . '/*.json') ?: [] as $file) {
            if (preg_match('~/(\d{4}-\d{2})\.json$~', str_replace('\\', '/', $file), $m) === 1
                && $m[1] <= $monthCutoff
                && @unlink($file)
            ) {
                $result['aggregatesDeleted']++;
            }
        }

        return $result;
    }

    /**
     * Every raw day file, from the legacy directory first (its lines are the
     * older ones of a split day) and then the current one, grouped by day.
     *
     * @return array<string, list<string>> day => files, sorted by day
     */
    public function rawFiles(): array
    {
        $files = [];
        foreach (array_filter([$this->legacyRawDir, $this->rawDir]) as $dir) {
            foreach (glob($dir . '/stats-*.jsonl') ?: [] as $file) {
                if (preg_match('~stats-(\d{4}-\d{2}-\d{2})\.jsonl$~', $file, $m) === 1) {
                    $files[$m[1]][] = $file;
                }
            }
        }
        ksort($files);

        return $files;
    }

    public function aggregateFile(string $month): string
    {
        return $this->aggregateDir . '/' . $month . '.json';
    }

    /**
     * The month's aggregate: an empty one when there is no file yet, NULL when
     * a file exists but cannot be read or does not parse as this month — the
     * caller must then leave the month alone (see the class docblock).
     *
     * @return array<string, mixed>|null
     */
    public function loadMonth(string $month): ?array
    {
        $file = $this->aggregateFile($month);
        $data = ['month' => $month];
        if (file_exists($file)) {
            $raw  = @file_get_contents($file);
            $data = $raw === false ? null : json_decode($raw, true);
            if (!is_array($data) || ($data['month'] ?? null) !== $month) {
                return null;
            }
        }

        $data['days_done'] ??= [];
        $data['days']      ??= [];
        $data['views']     ??= 0;
        $data['visitors']  ??= 0;
        $data['visits']    ??= 0;
        $data['capped']    ??= 0;
        foreach (self::TALLIES as $tally) {
            $data[$tally] ??= [];
        }
        $data[self::EVENT_PAGES] ??= [];

        return $data;
    }

    /**
     * Streams the files of one day into the month — all of them in one pass
     * (a day split across the legacy and the current directory is one day),
     * and only when every one of them opens: the month is untouched otherwise
     * and the caller reports the day. A malformed line is skipped; the caps
     * are applied per visitor key — a line without a key is bucketed under
     * `-`, so a broken salt cannot become an open door either.
     *
     * Visits and entry pages are derived here, in file order (appends are
     * serialised by LOCK_EX, so the order is the arrival order): a page view
     * of a key starts a visit when the key's previous page view is more than
     * VISIT_GAP_SECONDS back or absent; its path is the entry. A beacon event
     * neither starts nor extends a visit; a line without a key counts no
     * visit and no entry. The day file is the unit, so a visit across
     * midnight is two visits with two entries — a slight overstatement,
     * accepted rather than carrying state across files.
     *
     * @param list<string>         $files
     * @param array<string, mixed> $month
     * @param array<string, mixed> $result
     * @return bool false when a file could not be opened — nothing was folded then
     */
    private function foldDay(array $files, string $day, array &$month, array &$result): bool
    {
        $handles = [];
        foreach ($files as $file) {
            $handle = is_file($file) ? @fopen($file, 'rb') : false;
            if ($handle === false) {
                array_map('fclose', $handles);
                return false;
            }
            $handles[] = $handle;
        }

        $views    = 0;
        $events   = 0;
        $visits   = 0;
        $overflow = 0;
        $visitors = [];
        $perKey   = [];   // visitor key → event → count (the caps' memory — bounded by MAX_KEYS_PER_DAY)
        $lastSeen = [];   // visitor key → unix time of its last page view

        foreach ($handles as $handle) {
            while (($raw = fgets($handle)) !== false) {
                $row = json_decode(trim($raw), true);
                if (!is_array($row) || !is_string($row['event'] ?? null)) {
                    continue;
                }
                $result['lines']++;

                $event   = $row['event'];
                $isPage  = $event === StatsRecorder::PAGE_EVENT;
                $visitor = is_string($row['visitor'] ?? null) && $row['visitor'] !== '' ? $row['visitor'] : '-';

                if (!isset($perKey[$visitor]) && count($perKey) >= self::MAX_KEYS_PER_DAY) {
                    $month['capped']++;
                    $result['capped']++;
                    $overflow++;
                    continue;
                }
                $count = $perKey[$visitor][$event] = ($perKey[$visitor][$event] ?? 0) + 1;
                if ($count > ($isPage ? self::PAGE_VIEW_DAY_CAP : self::EVENT_DAY_CAP)) {
                    $month['capped']++;
                    $result['capped']++;
                    continue;
                }

                if ($visitor !== '-') {
                    $visitors[$visitor] = true;
                }

                $path  = (string)($row['path'] ?? '/');
                $query = '';
                if (($pos = strpos($path, '?')) !== false) {
                    $query = substr($path, $pos + 1);
                    $path  = substr($path, 0, $pos);
                }

                if ($isPage) {
                    $views++;
                    [$device, $browser] = array_pad(explode('/', (string)($row['device'] ?? 'desktop/other'), 2), 2, 'other');

                    [$sourceClass, $referrer] = StatsClassifier::splitSource((string)($row['source'] ?? 'direct'));

                    if ($visitor !== '-') {
                        $at = is_string($row['at'] ?? null) ? strtotime($row['at']) : false;
                        if ($at !== false) {
                            if (!isset($lastSeen[$visitor]) || $at - $lastSeen[$visitor] > self::VISIT_GAP_SECONDS) {
                                $visits++;
                                $this->tally($month['entries'], $path);
                            }
                            $lastSeen[$visitor] = $at;
                        }
                    }

                    $this->tally($month['pages'], $path);
                    $this->tally($month['sources'], $sourceClass);
                    if ($referrer !== null) {
                        $this->tally($month['referrers'], $referrer);
                    }
                    $this->tally($month['devices'], $device);
                    $this->tally($month['browsers'], $browser);
                    $this->tally($month['countries'], is_string($row['country'] ?? null) ? $row['country'] : '??');
                    $this->tally($month['languages'], (string)($row['lang'] ?? '?'));
                    $this->tally($month['status'], (string)($row['status'] ?? '200'));
                    if ($query !== '') {
                        $this->tally($month['campaigns'], $query);
                    }
                } else {
                    $events++;
                    $this->tally($month['events'], $event);
                    $this->tallyNested($month[self::EVENT_PAGES], $event, $path);
                }
            }
        }
        array_map('fclose', $handles);

        $month['days'][$day] = ['views' => $views, 'visitors' => count($visitors), 'visits' => $visits, 'events' => $events];
        $month['views']     += $views;
        $month['visitors']  += count($visitors);
        $month['visits']    += $visits;
        ksort($month['days']);
        if ($overflow > 0) {
            $result['overflow'][$day] = $overflow;
        }

        return true;
    }

    /** @param array<string, int> $tally */
    private function tally(array &$tally, string $key): void
    {
        if (!isset($tally[$key]) && count($tally) >= self::MAX_KEYS_PER_TALLY) {
            $key = self::REST_KEY;
        }
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }

    /** @param array<string, array<string, int>> $nested outer key capped like a tally, inner one a tally */
    private function tallyNested(array &$nested, string $outer, string $inner): void
    {
        if (!isset($nested[$outer]) && count($nested) >= self::MAX_KEYS_PER_TALLY) {
            $outer = self::REST_KEY;
        }
        $nested[$outer] ??= [];
        $this->tally($nested[$outer], $inner);
    }

    /**
     * Keeps the $max most frequent keys and sums the rest into REST_KEY.
     * Pure — the harness pins it.
     *
     * @param array<string, int> $tally
     * @return array<string, int> sorted by count, descending
     */
    public static function capTally(array $tally, int $max): array
    {
        $rest = (int)($tally[self::REST_KEY] ?? 0);
        unset($tally[self::REST_KEY]);
        arsort($tally);
        foreach (array_slice($tally, $max, null, true) as $count) {
            $rest += $count;
        }
        $tally = array_slice($tally, 0, $max, true);
        if ($rest > 0) {
            $tally[self::REST_KEY] = $rest;
        }

        return $tally;
    }

    /** Temp file + rename: a reader (the report) never sees a half-written month. */
    private function saveMonth(string $month, array $data): bool
    {
        if (!is_dir($this->aggregateDir) && !@mkdir($this->aggregateDir, 0755, true) && !is_dir($this->aggregateDir)) {
            return false;
        }
        foreach (self::TALLIES as $tally) {
            arsort($data[$tally]);
        }
        $data['referrers'] = self::capTally($data['referrers'], self::MAX_REFERRERS_PER_MONTH);
        foreach ($data[self::EVENT_PAGES] as $event => $paths) {
            $data[self::EVENT_PAGES][$event] = self::capTally($paths, self::MAX_PATHS_PER_EVENT);
        }
        uasort($data[self::EVENT_PAGES], static fn(array $a, array $b): int => array_sum($b) <=> array_sum($a));

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $file = $this->aggregateFile($month);
        $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}
