<?php

namespace Z77\Shared\Security;

use Z77\Shared\Libraries\ConfigLocator;

/**
 * The signed stamp a cross-origin form carries in place of a CSRF token (CROSS-ORIGIN-POST-001).
 *
 * A stamp is `{issuedAt}.{signature}`: the unix time it was issued and an HMAC-SHA256 over the
 * door's route and that time with the installation's key. It proves that the form was handed out
 * by this installation for exactly this door, and how long ago. Nothing is stored on the server.
 *
 * Valid from {@see MIN_AGE} seconds (a bot posts faster than a person fills a form) to
 * {@see MAX_AGE} seconds after issue.
 *
 * ⚠️ Not single-use: within its window a stamp can be sent again. The guard's per-IP throttle and
 * the action's own throttle are the answer; a replay store would need state, which this door has
 * not got.
 *
 * The key lives in `config/client/cross-origin.inc.php` (machine-local, never in git):
 *
 * ```php
 * return ['stampKey' => '<at least 32 random characters>'];
 * ```
 *
 * No file or no key → {@see fromConfig()} returns null and the door stays shut. A key that is
 * present but too short THROWS — a weak key is a configuration error. Replacing the key voids
 * every stamp that is out.
 */
final class FormStamp
{
    public const CONFIG_FILE    = 'cross-origin.inc.php';
    public const FIELD          = 'z77_stamp';
    public const MIN_AGE        = 3;
    public const MAX_AGE        = 7200;
    public const MIN_KEY_LENGTH = 32;

    private const PATTERN = '~^(\d{9,11})\.([A-Za-z0-9_-]{43})$~';

    public function __construct(private string $key)
    {
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new \RuntimeException(
                '❌ The cross-origin stamp key must be at least ' . self::MIN_KEY_LENGTH . ' characters long.'
            );
        }
    }

    /**
     * The installation's stamp service, or null when no key is configured.
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
            throw new \RuntimeException("❌ {$file} must return an array (['stampKey' => '…']).");
        }

        $key = $config['stampKey'] ?? '';
        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        return new self(trim($key));
    }

    /**
     * A stamp for the door `$route` (`group/controller/action`, as in `crossOriginPosts`),
     * issued now. The form sends it back in the body field {@see FIELD}.
     */
    public function issue(string $route, ?int $now = null): string
    {
        $issuedAt = $now ?? time();

        return $issuedAt . '.' . $this->sign($route, $issuedAt);
    }

    /**
     * True when `$stamp` was issued by this installation for `$route` between
     * {@see MIN_AGE} and {@see MAX_AGE} seconds ago. The signature is checked before the age.
     */
    public function verify(string $stamp, string $route, ?int $now = null): bool
    {
        if (preg_match(self::PATTERN, $stamp, $m) !== 1) {
            return false;
        }

        $issuedAt = (int) $m[1];
        if (!hash_equals($this->sign($route, $issuedAt), $m[2])) {
            return false;
        }

        $age = ($now ?? time()) - $issuedAt;

        return $age >= self::MIN_AGE && $age <= self::MAX_AGE;
    }

    private function sign(string $route, int $issuedAt): string
    {
        $mac = hash_hmac('sha256', 'cross-origin-post|' . $route . '|' . $issuedAt, $this->key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
