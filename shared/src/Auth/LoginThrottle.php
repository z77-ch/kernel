<?php

namespace Z77\Shared\Auth;

use Z77\Shared\Throttle\FileThrottle;

/**
 * Brute-force limit of the backend login (SEC-004): failed attempts per username+IP and per IP,
 * in a fixed 15-minute window ({@see FileThrottle}). A lock on the username alone would let
 * anyone lock any user out — hence the combination with the address.
 *
 * ⚠️ {@see tryAttempt()} RESERVES the attempt before `password_verify` and {@see succeeded()}
 * hands it back. Checking first and counting after the failure would let a burst of parallel
 * requests all pass the check before the first failure is counted.
 *
 * The limits are installation policy (`config/client/auth.inc.php`, keys
 * `loginFailuresPerUsernameIp` / `loginFailuresPerIp`). The constants here are the one default:
 * `auth.default.inc.php` is bound to them and an installation whose seed-once file predates the
 * keys falls back to them.
 *
 * ⚠️ The counters only know REMOTE_ADDR ({@see FileThrottle::normalizeIp()}). Behind a reverse
 * proxy all clients share one address and one per-IP counter.
 */
final class LoginThrottle
{
    public const FAILURES_PER_USERNAME_IP = 5;
    public const FAILURES_PER_IP          = 30;
    public const WINDOW_SECONDS           = 900;

    private FileThrottle $counter;

    public function __construct(
        string $dir,
        private int $failuresPerUsernameIp = self::FAILURES_PER_USERNAME_IP,
        private int $failuresPerIp = self::FAILURES_PER_IP,
    ) {
        $this->counter = new FileThrottle($dir);
    }

    /** `var/lib/throttle/login`: disposable, shared across releases — same reasoning as MemberThrottle::defaultDir(). */
    public static function defaultDir(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/login';
    }

    /**
     * True when this attempt may verify the password; the attempt is counted as a failure from
     * here on. False = over a limit: the caller MUST NOT call `password_verify`.
     *
     * Fails OPEN with a log line when the counters cannot be written (a dangling `var/lib`): a
     * broken disk must not lock every operator out of the backend that would let him fix it.
     */
    public function tryAttempt(string $username, ?string $clientIp, ?int $now = null): bool
    {
        try {
            $ipKey = $this->ipKey($clientIp);
            if (!$this->counter->allow($ipKey, $this->failuresPerIp, self::WINDOW_SECONDS, $now)) {
                return false;
            }
            if (!$this->counter->allow($this->userKey($username, $clientIp), $this->failuresPerUsernameIp, self::WINDOW_SECONDS, $now)) {
                // Refused without a guess: this attempt is no failure of the address.
                $this->counter->release($ipKey, self::WINDOW_SECONDS, $now);
                return false;
            }
            return true;
        } catch (\RuntimeException $e) {
            error_log('LoginThrottle: counters unavailable, login not limited — ' . $e->getMessage());
            return true;
        }
    }

    /** The password was right: takes back the reservation and clears this username+IP. */
    public function succeeded(string $username, ?string $clientIp, ?int $now = null): void
    {
        try {
            $this->counter->forget($this->userKey($username, $clientIp));
            $this->counter->release($this->ipKey($clientIp), self::WINDOW_SECONDS, $now);
        } catch (\RuntimeException $e) {
            error_log('LoginThrottle: counters unavailable — ' . $e->getMessage());
        }
    }

    /**
     * After a successful password reset: the owner of the mailbox must be able to log in right
     * away from this address, even after locking himself out. Clears the username+IP counter and
     * the address counter — someone who can read the account's mailbox gains nothing by guessing.
     */
    public function forgive(string $username, ?string $clientIp): void
    {
        $this->counter->forget($this->userKey($username, $clientIp));
        $this->counter->forget($this->ipKey($clientIp));
    }

    private function ipKey(?string $clientIp): string
    {
        return 'ip:' . $this->normalizedIp($clientIp);
    }

    private function userKey(string $username, ?string $clientIp): string
    {
        return 'user:' . mb_strtolower(trim($username)) . '|' . $this->normalizedIp($clientIp);
    }

    /** No usable address (CLI, malformed REMOTE_ADDR) is one shared bucket, never «unlimited». */
    private function normalizedIp(?string $clientIp): string
    {
        return FileThrottle::normalizeIp((string) $clientIp) ?? 'unknown';
    }
}
