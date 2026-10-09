<?php

namespace Z77\Shared\Stats;

/**
 * The monthly report as data — step 1.3 of docs/03-development/web-stats-bauplan.md,
 * see docs/topics/stats.md. One builder for all three readers: the backend
 * page, the link page and the mail (the mail uses the headline figures only).
 *
 * Reads the AGGREGATE only (`data/framework/stats/YYYY-MM.json` through
 * {@see StatsRollup::loadMonth()}), never a raw day file — those carry visitor
 * keys and live seven days; the aggregate is the product.
 *
 * What the report can and cannot say, and why the labels read as they do:
 *
 * - «Besucher (Tagessumme)» is the sum of the daily distinct keys (STATS-002);
 *   a monthly unique does not exist. Never label it «Besucher».
 * - Sources, devices, languages and countries are tallied per PAGE VIEW in
 *   the aggregate, not per visit, so the report counts page views there. For
 *   the sources the class `internal` (a click within the site) is left out:
 *   what remains are the page views that ARRIVED from outside.
 * - A comparison with the previous month is only shown when both months are
 *   whole: the report month is over, and counting already ran when the
 *   previous month began (its first counted day is the 1st, or the month
 *   before it has an aggregate). A partial first month — the month an
 *   installation started counting — would make every delta a lie.
 *   A day without a single visit has no raw file and therefore no entry in
 *   `days_done`; that is why the day count is not the test.
 */
final class StatsReport
{
    /** How many rows the long lists show. */
    public const TOP = 10;

    /** How many pages per event («on which page») are named. */
    public const TOP_EVENT_PAGES = 3;

    /** How many countries are named before «übrige». */
    public const TOP_COUNTRIES = 6;

    private const MONTHS = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    private const SOURCES = [
        'search'   => 'Suchmaschine',
        'direct'   => 'Direkt',
        'referral' => 'Andere Website',
        'social'   => 'Soziale Medien',
        'campaign' => 'Kampagne',
    ];

    private const DEVICES = [
        'mobile'  => 'Mobiltelefon',
        'desktop' => 'Computer',
        'tablet'  => 'Tablet',
    ];

    private const LANGUAGES = [
        'de' => 'Deutsch', 'fr' => 'Französisch', 'it' => 'Italienisch', 'en' => 'Englisch',
    ];

    /** Fallback when the intl extension is missing — the countries a Swiss site mostly sees. */
    private const COUNTRIES = [
        'CH' => 'Schweiz', 'DE' => 'Deutschland', 'FR' => 'Frankreich', 'IT' => 'Italien',
        'AT' => 'Österreich', 'LI' => 'Liechtenstein', 'US' => 'USA', 'GB' => 'Grossbritannien',
        'NL' => 'Niederlande', 'BE' => 'Belgien', 'ES' => 'Spanien', 'SE' => 'Schweden',
    ];

    public function __construct(private StatsRollup $rollup)
    {
    }

    public static function fromProject(): self
    {
        return new self(StatsRollup::fromProject());
    }

    /** The months that have an aggregate, newest first. */
    public function months(): array
    {
        $months = [];
        foreach (glob(dirname($this->rollup->aggregateFile('2000-01')) . '/*.json') ?: [] as $file) {
            if (preg_match('~(\d{4}-\d{2})\.json$~', str_replace('\\', '/', $file), $m) === 1) {
                $months[] = $m[1];
            }
        }
        rsort($months);

        return $months;
    }

    /** `2026-09` → «September 2026». */
    public static function monthLabel(string $month): string
    {
        [$year, $num] = array_map('intval', explode('-', $month));

        return (self::MONTHS[$num] ?? $month) . ' ' . $year;
    }

    /** The month before `YYYY-MM`. */
    public static function previousMonth(string $month): string
    {
        return (new \DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
    }

    /**
     * The report for one month. `null` when the month has no aggregate; a
     * month whose file exists but does not parse THROWS — the rollup blocks
     * such a month (STATS-010), and a report must not present it as empty.
     *
     * @param array<string, string> $eventLabels declared event name => label ({@see StatsEvents::declared()})
     * @return array<string, mixed>|null
     */
    public function build(string $month, array $eventLabels, ?int $now = null): ?array
    {
        if (!is_file($this->rollup->aggregateFile($month))) {
            return null;
        }
        $data = $this->load($month);

        $now      ??= time();
        $closed     = $month < date('Y-m', $now);
        $prevMonth  = self::previousMonth($month);
        $prev       = is_file($this->rollup->aggregateFile($prevMonth)) ? $this->load($prevMonth) : null;
        $compare    = $closed && $prev !== null && $this->isWhole($prevMonth, $prev);

        $daysDone = $data['days_done'];
        sort($daysDone);

        return [
            'month'        => $month,
            'monthLabel'   => self::monthLabel($month),
            'closed'       => $closed,
            'countedFrom'  => $daysDone[0] ?? null,
            'countedUntil' => $daysDone === [] ? null : end($daysDone),
            'startsLate'   => !$this->isWhole($month, $data),
            'previous'     => [
                'month'      => $prevMonth,
                'monthLabel' => self::monthLabel($prevMonth),
                'compared'   => $compare,
            ],
            'kpis'         => $this->kpis($data, $compare ? $prev : null),
            'days'         => $this->days($month, $data['days']),
            'pages'        => self::named(array_slice($data['pages'], 0, self::TOP, true)),
            'entries'      => self::named(array_slice($data['entries'], 0, self::TOP, true)),
            'events'       => $this->events($data, $eventLabels),
            'sources'      => $this->shares($this->sources($data['sources']), self::SOURCES),
            'referrers'    => self::named(array_slice($data['referrers'], 0, self::TOP, true)),
            'devices'      => $this->shares($data['devices'], self::DEVICES),
            'languages'    => $this->shares($data['languages'], self::LANGUAGES),
            'countries'    => $this->countries($data['countries']),
            'capped'       => (int) $data['capped'],
        ];
    }

    /**
     * The three figures of the mail — the same numbers the page shows at the
     * top, so the mail and the report cannot disagree.
     *
     * @param array<string, mixed> $report the result of {@see build()}
     * @return list<array{label: string, value: string}>
     */
    public static function headline(array $report): array
    {
        $out = [];
        foreach (array_slice($report['kpis'], 0, 3) as $kpi) {
            $out[] = ['label' => $kpi['label'], 'value' => self::formatNumber($kpi['value'], $kpi['decimals'])];
        }

        return $out;
    }

    /** Swiss number format: 11’942 and 3,5. */
    public static function formatNumber(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '’');
    }

    /** @return array<string, mixed> */
    private function load(string $month): array
    {
        $data = $this->rollup->loadMonth($month);
        if ($data === null) {
            throw new \RuntimeException(
                "The statistics aggregate {$month} exists but cannot be read — the rollup blocks it too (STATS-010); fix the file by hand."
            );
        }

        return $data;
    }

    /**
     * Was counting running the whole month? Its first counted day is the 1st,
     * or the month before it has an aggregate (counting started earlier, the
     * missing first days simply had no visitor).
     */
    private function isWhole(string $month, array $data): bool
    {
        $days = $data['days_done'];
        sort($days);
        if (($days[0] ?? null) === $month . '-01') {
            return true;
        }

        return is_file($this->rollup->aggregateFile(self::previousMonth($month)));
    }

    /** @return list<array{label: string, value: int|float, decimals: int, delta: ?string, trend: ?string}> */
    private function kpis(array $data, ?array $prev): array
    {
        $perVisit     = $data['visits'] > 0 ? $data['views'] / $data['visits'] : 0.0;
        $prevPerVisit = $prev !== null && $prev['visits'] > 0 ? $prev['views'] / $prev['visits'] : null;

        return [
            self::kpi('Besuche', (int) $data['visits'], $prev === null ? null : (int) $prev['visits']),
            self::kpi('Seitenaufrufe', (int) $data['views'], $prev === null ? null : (int) $prev['views']),
            self::kpi('Besucher (Tagessumme)', (int) $data['visitors'], $prev === null ? null : (int) $prev['visitors']),
            self::kpi('Seiten pro Besuch', round($perVisit, 1), $prevPerVisit === null ? null : round($prevPerVisit, 1), 1),
        ];
    }

    private static function kpi(string $label, int|float $value, int|float|null $prev, int $decimals = 0): array
    {
        $delta = null;
        $trend = null;
        if ($prev !== null && $prev > 0) {
            $diff  = $decimals > 0 ? round($value - $prev, $decimals) : (int) round(($value - $prev) / $prev * 100);
            $trend = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : null);
            $delta = ($diff > 0 ? '+ ' : ($diff < 0 ? '− ' : '± '))
                . self::formatNumber(abs($diff), $decimals)
                . ($decimals > 0 ? '' : ' %');
        }

        return ['label' => $label, 'value' => $value, 'decimals' => $decimals, 'delta' => $delta, 'trend' => $trend];
    }

    /** Every day of the month, with its visits — a day without data is 0, a day not yet counted null. */
    private function days(string $month, array $days): array
    {
        $count = (int) (new \DateTimeImmutable($month . '-01'))->format('t');
        $max   = 0;
        foreach ($days as $day) {
            $max = max($max, (int) ($day['visits'] ?? 0));
        }

        $out = [];
        for ($d = 1; $d <= $count; $d++) {
            $date   = sprintf('%s-%02d', $month, $d);
            $visits = isset($days[$date]) ? (int) ($days[$date]['visits'] ?? 0) : null;
            $out[]  = [
                'date'    => $date,
                'day'     => $d,
                'visits'  => $visits,
                'views'   => isset($days[$date]) ? (int) ($days[$date]['views'] ?? 0) : null,
                'percent' => $visits === null || $max === 0 ? 0 : (int) round($visits / $max * 100),
            ];
        }

        return $out;
    }

    /** @return list<array{name: string, label: string, count: int, per100: float, pages: list<array{label: string, count: int}>}> */
    private function events(array $data, array $labels): array
    {
        $counts = $data['events'];
        $names  = array_keys($labels);
        foreach (array_keys($counts) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = (string) $name;   // counted once, no longer declared: still shown, under its name
            }
        }

        $out = [];
        foreach ($names as $name) {
            $count = (int) ($counts[$name] ?? 0);
            $out[] = [
                'name'   => $name,
                'label'  => $labels[$name] ?? $name,
                'count'  => $count,
                'per100' => $data['visits'] > 0 ? round($count / $data['visits'] * 100, 1) : 0.0,
                'pages'  => self::named(array_slice($data['event_pages'][$name] ?? [], 0, self::TOP_EVENT_PAGES, true)),
            ];
        }

        return $out;
    }

    /** The source classes without `internal` — a click within the site is no arrival. */
    private function sources(array $sources): array
    {
        unset($sources['internal']);

        return $sources;
    }

    private function countries(array $countries): array
    {
        $named = array_slice($countries, 0, self::TOP_COUNTRIES, true);
        $rest  = array_sum(array_slice($countries, self::TOP_COUNTRIES, null, true));
        $total = array_sum($countries);

        $out = [];
        foreach ($named as $code => $count) {
            $out[] = self::share(self::countryName((string) $code), (int) $count, $total);
        }
        if ($rest > 0) {
            $out[] = self::share('übrige', $rest, $total);
        }

        return $out;
    }

    private static function countryName(string $code): string
    {
        if ($code === '??' || $code === '') {
            return 'unbekannt';
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $code, 'de');
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return self::COUNTRIES[$code] ?? $code;
    }

    /**
     * @param array<string, int>    $tally
     * @param array<string, string> $labels
     * @return list<array{label: string, count: int, percent: int}>
     */
    private function shares(array $tally, array $labels): array
    {
        arsort($tally);
        $total = array_sum($tally);
        $out   = [];
        foreach ($tally as $key => $count) {
            $key   = (string) $key;
            $label = $labels[$key] ?? ($key === '?' || $key === '' ? 'unbekannt' : $key);
            $out[] = self::share($label, (int) $count, $total);
        }

        return $out;
    }

    private static function share(string $label, int $count, int $total): array
    {
        return ['label' => $label, 'count' => $count, 'percent' => $total > 0 ? (int) round($count / $total * 100) : 0];
    }

    /**
     * A tally as rows; the rest bucket gets its German label here — the
     * aggregate keeps the English data key (stats.md: the report translates).
     *
     * @return list<array{label: string, count: int}>
     */
    private static function named(array $tally): array
    {
        $out = [];
        foreach ($tally as $key => $count) {
            $out[] = ['label' => $key === StatsRollup::REST_KEY ? 'übrige' : (string) $key, 'count' => (int) $count];
        }

        return $out;
    }
}
