<?php

namespace Z77\Shared\Auth;

/**
 * Brute-force guard for TOTP codes (B8 spec: 5 failures → 15-minute lock
 * window; while locked, even the RIGHT code is refused). File-based per
 * account, like the address throttle; a successful code clears the slate.
 *
 * The owner names the directory: under `var/lib/throttle`, next to the address
 * throttle — same class of file: a counter and a window, disposable. Deleting it
 * frees whoever is locked out, which is exactly what {@see reset()} does on a
 * valid code. The shared branch of `var/`, so a release switch does not free a
 * locked-out account (ADR-035).
 */
final class TotpGuard
{
    public const MAX_FAILURES  = 5;
    public const LOCK_SECONDS  = 900;

    public function __construct(private string $dir)
    {
    }

    public function isLocked(string $accountId, ?int $now = null): bool
    {
        $state = $this->read($accountId);

        return ($state['locked_until'] ?? 0) > ($now ?? time());
    }

    /**
     * Can this guard count at all? Tries a real write, because a full quota and a dangling link look
     * fine until then. A caller that fails closed asks before it judges a code: a right code clears
     * the counter and would never find out. With an account also its own file: one read-only
     * counter is enough to make the lock count nothing for him.
     */
    public function canCount(?string $accountId = null): bool
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
        $probe = $this->dir . '/.probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'x') !== 1) {
            return false;
        }
        @unlink($probe);

        if ($accountId !== null && is_file($this->file($accountId))) {
            $handle = @fopen($this->file($accountId), 'r+');
            if ($handle === false) {
                return false;
            }
            fclose($handle);
        }

        return true;
    }

    /**
     * Counts a wrong code; the fifth one starts the lock window. False = not counted, the disk
     * refused. What that means is the caller's: the backend refuses every code then, the member
     * flow carries on as before.
     */
    public function recordFailure(string $accountId, ?int $now = null): bool
    {
        $now   = $now ?? time();
        $state = $this->read($accountId);

        $state['failures'] = ($state['failures'] ?? 0) + 1;
        if ($state['failures'] >= self::MAX_FAILURES) {
            $state['locked_until'] = $now + self::LOCK_SECONDS;
            $state['failures']     = 0; // the window IS the penalty; afterwards the count restarts
        }

        return $this->write($accountId, $state);
    }

    /** A valid code clears failures and any expired lock remnants. False = the counter stayed. */
    public function reset(string $accountId): bool
    {
        return !is_file($this->file($accountId)) || @unlink($this->file($accountId));
    }

    private function read(string $accountId): array
    {
        $file = $this->file($accountId);

        return is_file($file) ? (array)json_decode((string)file_get_contents($file), true) : [];
    }

    private function write(string $accountId, array $state): bool
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        return @file_put_contents($this->file($accountId), json_encode($state), LOCK_EX) !== false;
    }

    private function file(string $accountId): string
    {
        return $this->dir . '/' . hash('sha256', $accountId) . '.json';
    }
}
