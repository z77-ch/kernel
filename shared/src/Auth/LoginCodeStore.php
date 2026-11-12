<?php

namespace Z77\Shared\Auth;

/**
 * One-time login codes, one record per REQUEST (ADR-058): the sign-in by a code that a person
 * receives by mail and types in, with no password. Built for the app users of module-api; a
 * member sign-in by code reuses it (ADR-058 A8) — the store knows no account type, only an
 * opaque `subject` (the id the caller signs in, or null).
 *
 * Why one record per request and not one per account (review B2/B4 of the app-user plan):
 *
 *   - a 6-digit code cannot be its own key — two people may hold the same code at once;
 *   - a record per account would let anybody lock a known address out by requesting codes
 *     (each request replacing the last). Here requests are independent: a request opens its
 *     OWN record and hands its requester a random NONCE (the caller puts it into a short-lived
 *     HttpOnly cookie). A guess without the nonce counts against nobody.
 *
 *   nonce        64 hex characters, random — the plaintext lives only in the requester's cookie
 *   record file  `<dir>/sha256(nonce).json` — `<dir>/nobody/sha256(nonce).json` for a record of nobody
 *   record       {subject: string|null, hash: sha256(nonce . code), tries: int, expires: int}
 *
 * A record is written for EVERY request — also for an unknown or inactive address, with
 * `subject` null (the anti-oracle rule of module-member's LoginFlow: the answer and the record
 * look the same; only its directory differs, which no requester sees). A null subject never
 * signs anybody in, even with the right code.
 *
 * Judging a code ({@see verify()}) happens under `flock` on the record file: read, count the
 * try, compare and — on success — spend the record, all inside one lock (the
 * `IdempotencyStore::run()` / `TotpGuard::acquire()` pattern). Never check-then-count: of two
 * parallel correct verifies exactly one gets the subject. A spent record is overwritten with a
 * tombstone while the lock is held and unlinked after it is released — Windows refuses to
 * delete a file another handle has open, and a waiting verifier must read «spent», not the code.
 *
 * Disposable by definition (ADR-034/035): wiping the directory only means «request a new
 * code». Live records are capped, in TWO quotas (security review S2): records of a subject in
 * `<dir>` ({@see $maxLive} — bounded anyway by the caller's per-address limits), records of
 * nobody in `<dir>/nobody` ({@see $maxNobody}). One quota for both let anonymous requests for
 * unknown addresses fill the store and silently stop every real sign-in. Past a cap a request
 * gets a nonce and no record — the answer stays the same for every address, so the cap is no
 * oracle either; issue() and verify() stat, sweep and count BOTH directories whichever record
 * they touch, so the split adds no timing difference. The over-cap log line is written once per
 * {@see TTL_SECONDS} and directory, not once per request.
 */
final class LoginCodeStore
{
    /** Digits of a code. */
    public const CODE_DIGITS = 6;

    /** Tries per record; the next one finds it spent. */
    public const MAX_TRIES = 5;

    /** A code lives 15 minutes — greylisting delays a first mail by minutes. */
    public const TTL_SECONDS = 900;

    /** Live records of a subject kept at most (disk). */
    public const MAX_LIVE = 5000;

    /** Live records of nobody kept at most (disk) — their own quota, review S2. */
    public const MAX_NOBODY = 5000;

    /** The nonce as the caller hands it back (its cookie value). */
    public const NONCE_PATTERN = '/^[0-9a-f]{64}$/';

    /** Where the records of nobody live, below the store's directory. */
    private const NOBODY_DIR = 'nobody';

    /** Touched when the over-cap line was logged; not a record (no `.json`), swept like one. */
    private const CAP_LOGGED = 'cap-logged.mark';

    public function __construct(
        private string $dir,
        private int $maxLive = self::MAX_LIVE,
        private int $maxNobody = self::MAX_NOBODY,
    ) {
    }

    /**
     * Opens a record for one request: a fresh nonce and code. `stored` is false when the record
     * could not be written (the cap, a refusing disk) — the caller answers exactly as before and
     * sends no code, since nothing could judge it.
     *
     * @param string|null $subject who this code signs in; null = nobody (unknown address)
     * @return array{nonce: string, code: string, stored: bool}
     */
    public function issue(?string $subject, ?int $now = null): array
    {
        $now   = $now ?? time();
        $nonce = bin2hex(random_bytes(32));
        $code  = str_pad((string) random_int(0, 10 ** self::CODE_DIGITS - 1), self::CODE_DIGITS, '0', STR_PAD_LEFT);

        $stored = false;
        if ($this->ensureDir($this->dir) && $this->ensureDir($this->nobodyDir())) {
            $this->sweep($now);
            // Both counted always: the work must not depend on whose record this is.
            $counts = [$this->countIn($this->dir), $this->countIn($this->nobodyDir())];
            [$dir, $live, $cap] = $subject !== null
                ? [$this->dir, $counts[0], $this->maxLive]
                : [$this->nobodyDir(), $counts[1], $this->maxNobody];
            if ($live < $cap) {
                $stored = $this->writeNew($dir . '/' . self::name($nonce), [
                    'subject' => $subject,
                    'hash'    => hash('sha256', $nonce . $code),
                    'tries'   => 0,
                    'expires' => $now + self::TTL_SECONDS,
                ]);
            } else {
                $this->logCapOnce($dir, $cap, $now);
            }
        }

        return ['nonce' => $nonce, 'code' => $code, 'stored' => $stored];
    }

    /**
     * Judges a code against the record of a nonce. The subject on success — the record is spent
     * then; null for everything else (no record, expired, tries used up, wrong code, a record of
     * nobody) — one answer, no tries-left count. Every call that finds a live record counts a
     * try, under the record's lock.
     */
    public function verify(string $nonce, string $code, ?int $now = null): ?string
    {
        $now = $now ?? time();
        if (preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            return null;
        }
        $code = preg_replace('/\s+/', '', $code) ?? '';
        // Both places looked at always: whether a record is somebody's must not show in time.
        $inMain   = is_file($this->dir . '/' . self::name($nonce));
        $inNobody = is_file($this->nobodyDir() . '/' . self::name($nonce));
        $file     = ($inMain ? $this->dir : $this->nobodyDir()) . '/' . self::name($nonce);

        $handle = $inMain || $inNobody ? @fopen($file, 'r+') : false;
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }

        $subject = null;
        $spend   = true;
        try {
            $record = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($record) || ($record['spent'] ?? false) === true
                || (int) ($record['expires'] ?? 0) < $now
                || (int) ($record['tries'] ?? 0) >= self::MAX_TRIES
            ) {
                return null;
            }

            $record['tries'] = (int) $record['tries'] + 1;
            $right = preg_match('/^\d{' . self::CODE_DIGITS . '}$/', $code) === 1
                && hash_equals((string) ($record['hash'] ?? ''), hash('sha256', $nonce . $code));

            if ($right || $record['tries'] >= self::MAX_TRIES) {
                $subject = $right && is_string($record['subject'] ?? null) && $record['subject'] !== '' ? $record['subject'] : null;
                self::rewrite($handle, ['spent' => true]);
                return $subject;
            }

            $spend = false;
            if (!self::rewrite($handle, $record)) {
                // A try that cannot be counted is not judged: closed, like a refusing TotpGuard.
                error_log('LoginCodeStore: a try could not be counted in ' . $this->dir . ' — record spent');
                self::rewrite($handle, ['spent' => true]);
                $spend = true;
            }
            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            if ($spend) {
                @unlink($file);
            }
        }
    }

    /**
     * Spends every live record of $subject — the account was deactivated or its address changed:
     * a code already on its way must not sign anybody in. Returns how many were spent.
     *
     * The record is read under its lock (review minor): a verify holding the lock may be about
     * to spend or rewrite it, and an unlocked read could see a half-written file.
     */
    public function forgetSubject(string $subject): int
    {
        $spent = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $handle = @fopen($file, 'r+');
            if ($handle === false) {
                continue;
            }
            $mine = false;
            if (flock($handle, LOCK_EX)) {
                $record = json_decode((string) stream_get_contents($handle), true);
                $mine   = is_array($record) && ($record['subject'] ?? null) === $subject;
                if ($mine) {
                    self::rewrite($handle, ['spent' => true]);
                    $spent++;
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            if ($mine) {
                @unlink($file);
            }
        }

        return $spent;
    }

    /**
     * Drops records past their lifetime by file time (a stat, no read): a record lives
     * TTL_SECONDS from its last write, so a file older than that is dead whatever it holds.
     * Both directories, the subject's and nobody's.
     */
    public function sweep(?int $now = null): void
    {
        $now = $now ?? time();
        foreach ([$this->dir, $this->nobodyDir()] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                $mtime = is_file($file) ? @filemtime($file) : false;
                if ($mtime !== false && $mtime + self::TTL_SECONDS < $now) {
                    @unlink($file);
                }
            }
        }
    }

    /** Records on disk that may still be live — a subject's and nobody's together. */
    public function liveCount(): int
    {
        return $this->countIn($this->dir) + $this->countIn($this->nobodyDir());
    }

    private function nobodyDir(): string
    {
        return $this->dir . '/' . self::NOBODY_DIR;
    }

    private function countIn(string $dir): int
    {
        return count(glob($dir . '/*.json') ?: []);
    }

    /** The record file name of a nonce: neither nonce nor code on disk. */
    private static function name(string $nonce): string
    {
        return hash('sha256', $nonce) . '.json';
    }

    /** The over-cap line, at most once per TTL_SECONDS and directory — a flood must not flood the log. */
    private function logCapOnce(string $dir, int $cap, int $now): void
    {
        $marker = $dir . '/' . self::CAP_LOGGED;
        $last   = @filemtime($marker);
        if ($last !== false && $last + self::TTL_SECONDS >= $now) {
            return;
        }
        @touch($marker, $now);
        error_log('LoginCodeStore: ' . $cap . ' live records in ' . $dir . ' — no record written (logged once per '
            . intdiv(self::TTL_SECONDS, 60) . ' minutes)');
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
            return true;
        }
        error_log('LoginCodeStore: could not create ' . $dir);

        return false;
    }

    /** A new record, complete or not at all: written aside, then renamed into place. */
    private function writeNew(string $file, array $record): bool
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($record, JSON_THROW_ON_ERROR)) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            error_log('LoginCodeStore: could not write a record in ' . $this->dir);
            return false;
        }

        return true;
    }

    /** @param resource $handle locked */
    private static function rewrite($handle, array $record): bool
    {
        $json = json_encode($record, JSON_THROW_ON_ERROR);

        return rewind($handle) && ftruncate($handle, 0)
            && fwrite($handle, $json) === strlen($json) && fflush($handle);
    }
}
