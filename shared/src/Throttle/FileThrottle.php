<?php

namespace Z77\Shared\Throttle;

/**
 * Generic fixed-window counter: one tiny file per key hash and window,
 * self-expiring, disposable by definition (lives under `var/lib` — wiping it
 * resets the running window and nothing else, ADR-034/ADR-035).
 *
 * Extracted from module-member's MemberThrottle (2026-09-02) so the API rate
 * limit and the member flows share one mechanism; MemberThrottle keeps its
 * public API and delegates here. The increment is atomic (flock around
 * read+write) — the original read unlocked and could lose concurrent counts.
 *
 * The caller namespaces its keys (`addr:…`, `tenant:…`, `ip:…`) BEFORE they
 * are hashed, so different buckets can never land on the same counter file.
 */
final class FileThrottle
{
    public function __construct(private string $dir)
    {
    }

    /** True while the key stays under the limit for the current window; counts the attempt. */
    public function allow(string $key, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        $now ??= time();
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            // Loud, not silent: behind a dangling var/lib symlink (release layout,
            // shared/var/lib missing) mkdir fails and every counter write after it
            // would be lost — the throttle would be off without anyone noticing.
            throw new \RuntimeException("FileThrottle: could not create counter directory {$this->dir}");
        }

        $window = intdiv($now, $windowSeconds);
        $file   = $this->dir . '/' . hash('sha256', $key) . '-' . $window;

        $fh = fopen($file, 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if ($fh !== false) {
                fclose($fh);
            }
            throw new \RuntimeException("FileThrottle: could not lock counter file {$file}");
        }

        try {
            $count = (int) stream_get_contents($fh);
            if ($count >= $limit) {
                return false;
            }
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, (string) ($count + 1));
            fflush($fh);
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Seconds until the current window ends — the `Retry-After` value for a denied attempt. */
    public function retryAfter(int $windowSeconds, ?int $now = null): int
    {
        $now ??= time();
        return (intdiv($now, $windowSeconds) + 1) * $windowSeconds - $now;
    }

    /**
     * Drops every window counter of one key — deliberate cleanup (a purged
     * tenant, an unblocked connection), so callers do not have to wait a
     * window out or know the file layout. Returns the number of counter
     * files removed. The caller owns the KEY NAMING — copying a consumer's
     * naming rule into another codebase to delete its counters is drift;
     * expose a forget call there instead.
     */
    public function forget(string $key): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/' . hash('sha256', $key) . '-*') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * IPv6 → its /64 prefix, IPv4 → itself, anything unparseable → null.
     *
     * ⚠️ A per-address IPv6 count would hand an attacker 2^64 fresh counters
     * out of one ordinary home prefix, i.e. no limit at all.
     * ⚠️ The input must come from REMOTE_ADDR — a throttle keyed on a header
     * the caller sets is a throttle the caller switches off.
     */
    public static function normalizeIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16) {
            // Keep the first 8 bytes, zero the rest — one counter per /64.
            $packed = substr($packed, 0, 8) . str_repeat("\0", 8);
        }

        $back = @inet_ntop($packed);

        return $back === false ? null : $back;
    }
}
