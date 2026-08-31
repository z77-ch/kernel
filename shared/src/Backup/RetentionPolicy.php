<?php

namespace Z77\Shared\Backup;

/**
 * Decides which archives a retention setting keeps — pure name-in, name-out,
 * no filesystem (the harness drives it with crafted timelines).
 *
 * Two forms, per backup type (`config/backup.inc.php`, `retention`):
 *
 *   'data' => 10                                        keep the newest 10
 *   'data' => ['last' => 2, 'daily' => 7,
 *              'weekly' => 4, 'monthly' => 12]          tiered (GFS)
 *
 * WHY TIERS EXIST — late discovery. «Keep the newest N» protects only
 * against loss, not against a mistake: change something today, notice it ten
 * days later, and every retained archive already contains the mistake — no
 * restore left. A tiered window thins with age instead of ending: all of the
 * last days, one per week for a month, one per month for a year. The depth
 * costs a handful of archives, not N-per-day.
 *
 * Tier semantics (borg/restic convention):
 *
 *   last     the newest N archives, unconditionally — protects the manual
 *            «backup before the risky change» from being thinned away by the
 *            same-day scheduled run
 *   daily    the newest archive of each of the N most recent distinct DAYS
 *            that have archives
 *   weekly   … per ISO week    monthly … per month    yearly … per year
 *
 * A count of 0 means UNLIMITED for that tier (same convention as the integer
 * form); a tier that is absent is unused. The kept set is the UNION of all
 * tiers — one archive may satisfy several. Everything no tier claims is
 * dropped.
 *
 * Timestamps come from the FILE NAME (`YYYY-MM-DD_HHMMSS_…`), never from
 * mtime: a copy or an account move resets mtime and would silently reshuffle
 * the buckets (same lesson as GEOIP-002). A name that does not parse is KEPT
 * — this class deletes nothing it cannot date.
 *
 * ⚠️ An unknown tier name throws. 'montly' silently ignored would silently
 * lose a whole tier of history — the failure mode this topic exists to avoid.
 */
final class RetentionPolicy
{
    private const TIERS = ['last', 'daily', 'weekly', 'monthly', 'yearly'];

    /**
     * The file names a retention setting DROPS, out of the given archives.
     *
     * @param list<string>          $fileNames archive names (BackupHistory::FILE_PATTERN), any order
     * @param int|array<string,int> $retention integer = keep newest N (0 = unlimited);
     *                                         array = tiered, see class docblock
     * @return list<string> names to delete, oldest last
     */
    public static function drops(array $fileNames, int|array $retention): array
    {
        // Newest first — the name's date-time prefix sorts chronologically.
        $sorted = $fileNames;
        rsort($sorted);

        if (is_int($retention)) {
            if ($retention <= 0) {
                return [];
            }

            return array_slice($sorted, $retention);
        }

        if ($retention === []) {
            // An empty tier map claims nothing — taken literally it would
            // delete EVERY archive the moment one is written. No retention
            // config can mean «drop it all»; empty reads as unlimited.
            return [];
        }

        foreach (array_keys($retention) as $tier) {
            if (!in_array($tier, self::TIERS, true)) {
                throw new \RuntimeException(
                    "Unknown retention tier '{$tier}' — valid: " . implode(', ', self::TIERS) . '.'
                );
            }
        }

        $keep = [];
        foreach ($retention as $tier => $count) {
            $count   = (int) $count;
            $buckets = [];
            $index   = 0;
            foreach ($sorted as $name) {
                $bucket = $tier === 'last' ? (string) $index++ : self::bucket($name, $tier);
                if ($bucket === null || isset($buckets[$bucket])) {
                    continue; // unparseable (kept below), or this period already has its archive
                }
                if ($count > 0 && count($buckets) >= $count) {
                    break; // this tier's periods are used up
                }
                $buckets[$bucket] = true;
                $keep[$name]      = true;
            }
        }

        return array_values(array_filter(
            $sorted,
            static fn(string $name): bool => !isset($keep[$name]) && self::bucket($name, 'daily') !== null,
        ));
    }

    /** Period key of an archive name for a tier, or null when the name has no date. */
    private static function bucket(string $name, string $tier): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})_\d{6}_/', $name, $m)) {
            return null;
        }

        if ($tier === 'weekly') {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', "{$m[1]}-{$m[2]}-{$m[3]}");

            // ISO year-week, so the turn of the year buckets correctly
            // (Dec 31 and Jan 1 can share a week).
            return $day === false ? null : $day->format('o-W');
        }

        return match ($tier) {
            'daily'   => "{$m[1]}-{$m[2]}-{$m[3]}",
            'monthly' => "{$m[1]}-{$m[2]}",
            'yearly'  => $m[1],
            default   => null,
        };
    }
}
