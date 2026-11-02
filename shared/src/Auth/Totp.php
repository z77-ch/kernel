<?php

namespace Z77\Shared\Auth;

/**
 * TOTP (RFC 6238 over RFC 4226): time-based 6-digit codes, 30-second period,
 * HMAC-SHA1 — the parameter set every authenticator app speaks. Verified
 * against the RFC test vectors in tests/backend-2fa.php.
 *
 * verify() accepts ±1 period of clock drift between the phone and the
 * server; more would soften the "time-based" in the factor. In the kernel
 * because both doors use it: the member login and the backend login.
 */
final class Totp
{
    public const PERIOD_SECONDS = 30;
    public const DIGITS         = 6;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** New shared secret: 20 random bytes as Base32 (what the app expects). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The current code for a Base32 secret at $now. */
    public static function code(string $base32Secret, int $now): string
    {
        $counter = intdiv($now, self::PERIOD_SECONDS);
        $key     = self::base32Decode($base32Secret);

        $hash   = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value  = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % (10 ** self::DIGITS);

        return str_pad((string)$value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Constant-time check of a submitted code, tolerating ±1 period of drift. */
    public static function verify(string $base32Secret, string $submitted, int $now): bool
    {
        return self::acceptedStep($base32Secret, $submitted, $now) !== null;
    }

    /**
     * The time step a submitted code belongs to, or null: the same check as {@see verify()}, for a
     * caller that rejects replay (RFC 6238 §5.2). A step at or below $afterStep does not count, so
     * neither the accepted code nor an older one within the drift works a second time. The caller
     * stores the returned step.
     */
    public static function acceptedStep(string $base32Secret, string $submitted, int $now, int $afterStep = -1): ?int
    {
        $submitted = trim($submitted);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $submitted)) {
            return null;
        }

        $current = intdiv($now, self::PERIOD_SECONDS);
        foreach ([0, -1, 1] as $drift) {
            if ($current + $drift > $afterStep
                && hash_equals(self::code($base32Secret, $now + $drift * self::PERIOD_SECONDS), $submitted)
            ) {
                return $current + $drift;
            }
        }

        return null;
    }

    /** The otpauth:// URI the QR code carries (issuer + account label shown in the app). */
    public static function otpauthUri(string $issuer, string $accountLabel, string $base32Secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountLabel)
            . '?secret=' . $base32Secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD_SECONDS;
    }

    // ── Base32 (RFC 4648, no padding) ──────────────────────────────────────

    public static function base32Encode(string $bytes): string
    {
        $bits   = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $base32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(trim($base32))) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue; // tolerate spacing/padding chars in hand-typed input
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
