<?php

namespace Z77\Shared\Auth;

/**
 * Installation-wide password strength tier (config key `passwordTier` in
 * `config/auth.inc.php`). The tier is INPUT to {@see PasswordPolicy}: it sets the
 * minimum length the policy evaluates against. It lives next to the policy (shared
 * domain), NOT in core — unlike {@see \Z77\Core\Config\AuthRole}, which the engine
 * itself checks, the tier is only consumed by the shared password policy.
 *
 * Two of the three tiers only NAG (the policy classifies, never blocks — see
 * `security.md`); `veryStrong` is the single tier that hard-blocks a too-weak
 * password on save ({@see blocksWeak()}).
 */
enum PasswordTier: string
{
    /** Lenient: a low length floor; weak passwords are flagged but rarely. */
    case NotStrong = 'notStrong';

    /** Default: NIST-style length + blocklist; weak only nags. */
    case Strong = 'strong';

    /** Strict: higher length AND hard-blocks a weak password on save. */
    case VeryStrong = 'veryStrong';

    /** Minimum length the policy treats as "not too short" for this tier. */
    public function minLength(): int
    {
        return match ($this) {
            self::NotStrong  => 8,
            self::Strong     => 12,
            self::VeryStrong => 16,
        };
    }

    /**
     * Whether a password that fails the policy is rejected on save (true) or only
     * drives the every-login nag (false). Only `veryStrong` blocks.
     */
    public function blocksWeak(): bool
    {
        return $this === self::VeryStrong;
    }

    /**
     * Resolves a config value to a tier. An ABSENT value (null / empty string) is
     * legitimate → the safe {@see Strong} default. An unknown NON-EMPTY value is a
     * misconfiguration of a security control and is REFUSED with a fatal error —
     * never silently downgraded (a typo'd `veryStrong` must not quietly become
     * `strong`, which would drop the hard block the operator intended).
     *
     * @throws \ValueError on an unknown non-empty tier name
     */
    public static function fromName(?string $name): self
    {
        if ($name === null || $name === '') {
            return self::Strong;
        }
        return self::tryFrom($name) ?? throw new \ValueError(sprintf(
            'Invalid passwordTier "%s" (config/auth.inc.php) — must be one of: %s. '
            . 'Refusing to fall back silently on a security setting.',
            $name,
            implode(', ', array_column(self::cases(), 'value'))
        ));
    }
}
