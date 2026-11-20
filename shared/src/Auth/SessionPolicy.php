<?php

namespace Z77\Shared\Auth;

use Z77\Core\DI;

/**
 * How long a backend login lasts (login.md LOGIN-SESSION-001) — installation policy from
 * `config/client/auth.inc.php`, read at ONE place. The constants here are the one default:
 * `auth.default.inc.php` is bound to them, and an installation whose seed-once file predates
 * the keys falls back to them (the pattern of {@see LoginThrottle}).
 *
 *   sessionIdleMinutes         without a request for this long → signed out
 *   sessionLifetimeHours       signed out this long after the login, whatever happened since
 *   rememberDevice             «Auf diesem Gerät angemeldet bleiben» offered and accepted at all
 *   rememberDeviceDays         how long a remembered device signs in without the password
 *   rememberSkipsSecondFactor  a remembered device also skips the TOTP code
 *
 * ⚠️ Both clocks are enforced by the FRAMEWORK, per request, from timestamps inside the
 * session ({@see expiryOf()}, called by AuthService::getCurrentUser()). PHP's session garbage
 * collection only removes files nobody can use any more ({@see gcMaxLifetime()}); it decides
 * nothing. Before this, a backend session ended whenever the host's collector happened to run.
 *
 * The clocks belong to the BACKEND identity. The member door keeps its own idle clock
 * (MemberSession), public forms and the language choice have none.
 */
final class SessionPolicy
{
    public const IDLE_MINUTES                 = 60;
    public const LIFETIME_HOURS               = 12;
    public const REMEMBER_DEVICE              = true;
    public const REMEMBER_DEVICE_DAYS         = 30;
    public const REMEMBER_SKIPS_SECOND_FACTOR = true;

    /**
     * The session files: the SHARED branch of `var/` (ADR-035), so a release switch signs nobody
     * out; disposable, outside every backup (`var` is excluded) and outside the document root.
     */
    public const SAVE_DIR = 'var/lib/sessions';

    /**
     * No session file is collected earlier than this, however short the backend's clocks are
     * set: the session also carries what other doors keep in it (the member's two-hour idle
     * clock, a public form's guard), and those own their timing.
     */
    private const GC_FLOOR_SECONDS = 14400;

    public const EXPIRED_IDLE     = 'idle';
    public const EXPIRED_LIFETIME = 'lifetime';

    public function __construct(
        public readonly int $idleSeconds = self::IDLE_MINUTES * 60,
        public readonly int $lifetimeSeconds = self::LIFETIME_HOURS * 3600,
        public readonly bool $rememberDevice = self::REMEMBER_DEVICE,
        public readonly int $rememberDeviceSeconds = self::REMEMBER_DEVICE_DAYS * 86400,
        public readonly bool $rememberSkipsSecondFactor = self::REMEMBER_SKIPS_SECOND_FACTOR,
    ) {
    }

    /**
     * The installation's policy. Without a config manager (an offline harness, a container that
     * is not up) the defaults apply; a value that IS configured and makes no sense throws.
     */
    public static function fromConfig(): self
    {
        try {
            $config = DI::getConfigManager()->getBaseConfig('config/auth', throwError: false);
        } catch (\Throwable) {
            return new self();
        }

        return self::fromValues([
            'sessionIdleMinutes'        => $config->get('sessionIdleMinutes'),
            'sessionLifetimeHours'      => $config->get('sessionLifetimeHours'),
            'rememberDevice'            => $config->get('rememberDevice'),
            'rememberDeviceDays'        => $config->get('rememberDeviceDays'),
            'rememberSkipsSecondFactor' => $config->get('rememberSkipsSecondFactor'),
        ]);
    }

    /**
     * The rule for reading the keys, pure. An ABSENT key is the default; a present one that is
     * not a positive whole number (or not a boolean) is a `\ValueError` — a typo must not quietly
     * switch a timeout off or a second factor away (security.md, the rule for typed auth values).
     *
     * @param array<string, mixed> $values
     */
    public static function fromValues(array $values): self
    {
        return new self(
            self::positive($values, 'sessionIdleMinutes', self::IDLE_MINUTES) * 60,
            self::positive($values, 'sessionLifetimeHours', self::LIFETIME_HOURS) * 3600,
            self::flag($values, 'rememberDevice', self::REMEMBER_DEVICE),
            self::positive($values, 'rememberDeviceDays', self::REMEMBER_DEVICE_DAYS) * 86400,
            self::flag($values, 'rememberSkipsSecondFactor', self::REMEMBER_SKIPS_SECOND_FACTOR),
        );
    }

    /**
     * Has this backend identity run out? `seen_at` is the last request, `login_at` the sign-in;
     * both live in the `auth_user` slot. A slot from before the clocks existed has neither and is
     * not expired: the caller stamps it and the clocks start there.
     *
     * @param  array<string, mixed> $authUser the session's `auth_user`
     * @return ?string EXPIRED_IDLE, EXPIRED_LIFETIME or null
     */
    public function expiryOf(array $authUser, int $now): ?string
    {
        $loginAt = (int) ($authUser['login_at'] ?? 0);
        $seenAt  = (int) ($authUser['seen_at'] ?? 0);

        if ($loginAt > 0 && $now - $loginAt >= $this->lifetimeSeconds) {
            return self::EXPIRED_LIFETIME;
        }
        if ($seenAt > 0 && $now - $seenAt >= $this->idleSeconds) {
            return self::EXPIRED_IDLE;
        }

        return null;
    }

    /** `session.gc_maxlifetime`: a file untouched for this long serves no clock any more. */
    public function gcMaxLifetime(): int
    {
        return max($this->idleSeconds, $this->lifetimeSeconds, self::GC_FLOOR_SECONDS);
    }

    public static function saveDir(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/' . self::SAVE_DIR;
    }

    /** @param array<string, mixed> $values */
    private static function positive(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_int($value) || $value < 1) {
            throw new \ValueError(sprintf(
                'Invalid %s %s (config/client/auth.inc.php) — must be a whole number of at least 1.',
                $key,
                var_export($value, true)
            ));
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function flag(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_bool($value)) {
            throw new \ValueError(sprintf(
                'Invalid %s %s (config/client/auth.inc.php) — must be true or false.',
                $key,
                var_export($value, true)
            ));
        }

        return $value;
    }
}
