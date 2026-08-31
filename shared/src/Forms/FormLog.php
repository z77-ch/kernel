<?php

namespace Z77\Shared\Forms;

/**
 * One line per submit of a geo-guarded public form — the answer to «where do
 * these come from?», written where an operator can read it.
 *
 * This is bookkeeping, NOT a gate. It decides nothing and blocks nobody; it
 * records what the gates already decided so a human can look afterwards and
 * judge whether anything systematic is happening. Blocking rules that are
 * built on top read this file's evidence, never the other way round.
 *
 * The writer is {@see PublicFormHandler}: switching the geo guard on IS
 * switching this log on (one switch, not two), and the handler hands in the
 * ip and the country it already read for the gate — one read, one lookup per
 * submit, so the log can never show a different origin than the one the gate
 * decided on.
 *
 * ⚠️ Nothing here throws. It sits behind a public form; a full disk or a
 * read-only directory must cost a log line, never a submit. Every failure
 * path returns silently.
 *
 * Storage: `logs/form-YYYY-MM.jsonl`, one JSON object per line, appended
 * with LOCK_EX. Monthly files because that makes expiry a file deletion
 * instead of a rewrite — a log that has to be rewritten to prune is a log
 * that corrupts under concurrency.
 *
 * ⚠️ PERSONAL DATA. A line carries the IP, the country, the user agent and —
 * only where the form's {@see FormDefinition::identityField()} declares one —
 * an identifying field such as the e-mail address. That is a different
 * purpose from the abuse counters (which store only a hash, and only for
 * their counting window), so it needs its own sentence in the privacy policy
 * and its own retention — see {@see self::sweep()}. Do not widen the record
 * without widening that text.
 */
final class FormLog
{
    /** Relative to ABS_BASE_PATH. */
    public const DIR = 'logs';

    /** Files older than this are removed by {@see self::sweep()}. */
    public const RETENTION_DAYS = 90;

    /** Set by the flow during a submit, consumed by the next write(). */
    private static ?string $note = null;

    /**
     * A detail only the project's FLOW knows — `new`, `known`, `throttled`.
     * The form handler cannot tell these apart: it sees one bool, because the
     * page must answer identically whether or not the address has an account
     * (anti-oracle). Server-side the difference is exactly what tells a
     * probe from a customer, so it is recorded.
     *
     * ⚠️ Ordering: the flow runs inside the handler's dispatch, and the
     * handler writes the line AFTER it — so a note set here is picked up by
     * the write() belonging to the same submit. It is cleared on consumption,
     * so a note without a following write cannot leak into a later line.
     */
    public static function note(string $detail): void
    {
        self::$note = $detail;
    }

    /**
     * Records one attempt.
     *
     * @param string      $form     the form's {@see FormDefinition::guardKey()}
     * @param string      $outcome  the PublicFormHandler outcome
     * @param string|null $ip       validated client address, read ONCE by the
     *                              handler and shared with the gate
     * @param string|null $country  resolved by the handler at the moment of
     *                              the attempt — the country map changes over
     *                              time, so an IP looked up next month may
     *                              answer differently than it did today
     * @param string|null $identity the declared identifying field's value, if
     *                              the definition opted in (data minimisation:
     *                              absent by default)
     * @param array<string, scalar|null> $extra additional named facts the
     *                              caller pinned to the handler (e.g. origin)
     */
    public static function write(
        string $form,
        string $outcome,
        ?string $ip,
        ?string $country,
        ?string $identity = null,
        array $extra = [],
    ): void {
        try {
            $row = [
                'at'       => date('c'),
                'form'     => $form,
                'outcome'  => $outcome,
                'ip'       => $ip,
                'country'  => $country,
                'detail'   => self::$note,
                'identity' => $identity !== null && $identity !== '' ? $identity : null,
                'ua'       => self::userAgent(),
            ];
            self::$note = null;

            foreach ($extra as $key => $value) {
                if (is_string($key) && $key !== '' && !isset($row[$key])) {
                    $row[$key] = is_scalar($value) || $value === null ? $value : null;
                }
            }

            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }

            $file = self::file();
            if ($file === null) {
                return;
            }

            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // See the class docblock: a log is never worth a failed submit.
        }
    }

    /**
     * Deletes monthly files older than the retention. Called by the cleanup
     * job; safe to call at any time.
     *
     * @return int how many files were removed
     */
    public static function sweep(?int $now = null): int
    {
        $now ??= time();
        $dir = self::dir();
        if ($dir === null) {
            return 0;
        }

        $removed = 0;
        $cutoff  = $now - self::RETENTION_DAYS * 86400;

        foreach (glob($dir . '/form-*.jsonl') ?: [] as $file) {
            // The FILE's month decides, not its mtime: an untouched month must
            // still expire, and a late append must not extend a whole month's
            // life. The cutoff is the END of that month.
            if (!preg_match('/form-(\d{4})-(\d{2})\.jsonl$/', $file, $m)) {
                continue;
            }
            $monthEnd = mktime(23, 59, 59, (int)$m[2] + 1, 0, (int)$m[1]);
            if ($monthEnd < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @return list<array<string, mixed>> newest first — the backend view reads this */
    public static function recent(int $limit = 200): array
    {
        $dir = self::dir();
        if ($dir === null) {
            return [];
        }

        $files = glob($dir . '/form-*.jsonl') ?: [];
        rsort($files); // newest month first by name, which sorts chronologically

        $rows = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $rows[] = $row;
                }
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /** Trimmed to a sane length — a user agent is a free-text field from outside. */
    private static function userAgent(): ?string
    {
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') {
            return null;
        }

        return mb_substr($ua, 0, 250);
    }

    private static function file(): ?string
    {
        $dir = self::dir();
        if ($dir === null) {
            return null;
        }

        return $dir . '/form-' . date('Y-m') . '.jsonl';
    }

    private static function dir(): ?string
    {
        if (!defined('ABS_BASE_PATH')) {
            return null;
        }

        $dir = ABS_BASE_PATH . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return $dir;
    }
}
