<?php

namespace Z77\Shared\Stats;

use Z77\Shared\Libraries\ConfigLocator;

/**
 * The access link of the statistics report — step 1.4 of
 * docs/03-development/web-stats-bauplan.md, see docs/topics/stats.md.
 *
 * A token is `{YYYY-MM}.{expires}.{signature}`: the report month, the unix
 * time the link stops working, and an HMAC-SHA256 over both with the
 * installation's key. It opens exactly ONE month, read-only, without a login
 * and without anything stored on the server — the link carries everything,
 * the key proves it was minted here. Month and expiry are readable on
 * purpose: they are no secret, and a support question («which link did the
 * client get?») is answered by looking at the URL.
 *
 * The key lives in `config/client/stats.inc.php` (machine-local, per server,
 * never in git — the pattern of the GeoIP key):
 *
 * ```php
 * return ['reportKey' => '<at least 32 random characters>'];
 * ```
 *
 * No file, or no key in it → {@see fromConfig()} returns null: the tokenized
 * page and the mail are off, counting goes on (acceptance 7). A key that is
 * present but too short THROWS — a weak key is a configuration error, not a
 * way of switching the link off. Replacing the key invalidates every link
 * that is out; that is the revocation, there is no other.
 */
final class ReportToken
{
    public const CONFIG_FILE = 'stats.inc.php';

    /**
     * Where the link opens: `{canonicalBaseUrl}/stats/report/{token}` — a
     * reserved route of module-backend (backendConfig `reservedRoutes`), so the
     * address carries no `/backend` and works on any installation.
     */
    public const LINK_PATH = '/stats/report';

    /** How long a minted link works (owner decision in the Bauplan, step 1.4). */
    public const VALID_DAYS = 10;

    public const MIN_KEY_LENGTH = 32;

    public const OK      = 'ok';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    private const TOKEN_PATTERN = '~^(\d{4}-(?:0[1-9]|1[0-2]))\.(\d{9,11})\.([A-Za-z0-9_-]{43})$~';

    public function __construct(private string $key)
    {
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new \RuntimeException(
                '❌ The statistics report key must be at least ' . self::MIN_KEY_LENGTH . ' characters long.'
            );
        }
    }

    /**
     * The installation's token service, or null when no key is configured.
     *
     * @throws \RuntimeException on a malformed config file or a key that is too short
     */
    public static function fromConfig(?string $baseDir = null): ?self
    {
        $file = ConfigLocator::path(self::CONFIG_FILE, $baseDir);
        if ($file === null) {
            return null;
        }

        $config = require $file;
        if (!is_array($config)) {
            throw new \RuntimeException("❌ {$file} must return an array (['reportKey' => '…']).");
        }

        $key = $config['reportKey'] ?? '';
        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        return new self(trim($key));
    }

    /**
     * When a link minted now stops working: the end of the {@see VALID_DAYS}th
     * day after today (a link sent on the 1st works through the 11th, 23:59:59).
     */
    public static function expiresAt(?int $now = null): int
    {
        $today = (new \DateTimeImmutable('@' . ($now ?? time())))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0);

        return $today->modify('+' . (self::VALID_DAYS + 1) . ' days')->getTimestamp() - 1;
    }

    /** A token for $month (`YYYY-MM`) that works until $expiresAt (unix time). */
    public function mint(string $month, int $expiresAt): string
    {
        if (preg_match('~^\d{4}-(0[1-9]|1[0-2])$~', $month) !== 1) {
            throw new \InvalidArgumentException("Not a report month: '{$month}' (YYYY-MM).");
        }

        return $month . '.' . $expiresAt . '.' . $this->sign($month, $expiresAt);
    }

    /**
     * Reads a token. The signature is checked BEFORE the expiry, so «expired»
     * is only ever said about a link this installation really minted — a
     * forged token is `invalid`, never a hint that the format was right.
     *
     * @return array{status: string, month: ?string, expiresAt: ?int}
     */
    public function verify(string $token, ?int $now = null): array
    {
        $invalid = ['status' => self::INVALID, 'month' => null, 'expiresAt' => null];

        if (preg_match(self::TOKEN_PATTERN, $token, $m) !== 1) {
            return $invalid;
        }

        [, $month, $expires, $signature] = $m;
        if (!hash_equals($this->sign($month, (int) $expires), $signature)) {
            return $invalid;
        }

        return [
            'status'    => (int) $expires < ($now ?? time()) ? self::EXPIRED : self::OK,
            'month'     => $month,
            'expiresAt' => (int) $expires,
        ];
    }

    private function sign(string $month, int $expiresAt): string
    {
        $mac = hash_hmac('sha256', 'stats-report|' . $month . '|' . $expiresAt, $this->key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
