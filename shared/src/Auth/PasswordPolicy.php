<?php

namespace Z77\Shared\Auth;

/**
 * Single source of truth for "is this password strong enough?".
 *
 * Deliberately follows modern guidance (NIST SP 800-63B): strength is driven by
 * LENGTH + a BLOCKLIST, NOT by character-class composition rules (which only
 * push users to predictable patterns). It classifies — it does NOT block: the
 * z77 policy allows weak passwords but nags on every login (see login flow). The
 * single exception is the `veryStrong` {@see PasswordTier}, where callers turn a
 * weak verdict into a hard block on save (the policy itself still only classifies).
 *
 * The minimum length is supplied by the installation-wide {@see PasswordTier}
 * (config key `passwordTier`), NOT hardcoded — callers pass the configured tier.
 *
 * Used by the installer (admin provisioning), `LoginUserController`
 * (add/edit → `passwordWeak` flag) and the login nag. One ruleset, three callers.
 */
final class PasswordPolicy
{

    /**
     * Common / shipped / trivial passwords. Lower-case, compared case-insensitively.
     * Not exhaustive — the goal is to catch the catastrophic, well-known values
     * (including z77's former defaults), not to mirror a full breach corpus.
     */
    private const BLOCKLIST = [
        'password', 'passwort', 'admin', 'administrator', 'root', 'login',
        'admin1234', 'guest1234', 'password1', 'passwort1', 'geheim',
        '12345678', '123456789', '1234567890', '11111111', '00000000',
        'qwertz', 'qwerty', 'qwertzuiop', 'asdfghjkl', 'letmein', 'welcome',
        'iloveyou', 'changeme', 'default', 'test1234',
    ];

    private function __construct() {}

    /**
     * @param string $password     plaintext to evaluate
     * @param string[] $forbidden  context terms that must not appear in the
     *                             password (e.g. username, site name)
     * @param PasswordTier $tier   installation-wide tier supplying the min length
     * @return array{weak: bool, reasons: string[]}  reasons are German, user-facing
     */
    public static function evaluate(string $password, array $forbidden = [], PasswordTier $tier = PasswordTier::Strong): array
    {
        $reasons   = [];
        $lower     = mb_strtolower(trim($password));
        $minLength = $tier->minLength();

        if (mb_strlen($password) < $minLength) {
            $reasons[] = 'zu kurz (mindestens ' . $minLength . ' Zeichen)';
        }

        foreach ($forbidden as $term) {
            $term = mb_strtolower(trim((string) $term));
            if ($term !== '' && mb_strlen($term) >= 3 && str_contains($lower, $term)) {
                $reasons[] = 'enthält einen leicht erratbaren Teil (z.B. Benutzername)';
                break;
            }
        }

        if (in_array($lower, self::BLOCKLIST, true)) {
            $reasons[] = 'ist ein gebräuchliches/bekanntes Passwort';
        }

        // Digits-only passwords are PIN-like (dates, phone numbers, sequences) —
        // low practical entropy regardless of length.
        if ($lower !== '' && preg_match('/^\d+$/', $lower)) {
            $reasons[] = 'besteht nur aus Ziffern';
        }

        if (self::isSequentialOrRepeated($lower)) {
            $reasons[] = 'ist zu einfach (Folge oder Wiederholung)';
        }

        return ['weak' => $reasons !== [], 'reasons' => $reasons];
    }

    /** Convenience: just the boolean verdict. */
    public static function isWeak(string $password, array $forbidden = [], PasswordTier $tier = PasswordTier::Strong): bool
    {
        return self::evaluate($password, $forbidden, $tier)['weak'];
    }

    /**
     * Coarse strength tier for a UI meter: 0 = weak (fails the policy),
     * 1 = ok, 2 = strong. Driven by the SAME length + blocklist rules, never by
     * character-class composition. The client meter mirrors this; this method is
     * the server-side authority (installer output, future setup form).
     */
    public static function strength(string $password, array $forbidden = [], PasswordTier $tier = PasswordTier::Strong): int
    {
        if ($password === '' || self::isWeak($password, $forbidden, $tier)) {
            return 0;
        }
        return mb_strlen($password) >= 16 ? 2 : 1;
    }

    /**
     * Trivial patterns: a single repeated character (`aaaaaaaa`), or a run that
     * is purely an ascending/descending sequence of adjacent code points
     * (`12345678`, `abcdefgh`). Mixed/real passwords fail both checks.
     */
    private static function isSequentialOrRepeated(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        if (preg_match('/^(.)\1+$/u', $s)) {
            return true;
        }

        $chars = mb_str_split($s);
        if (count($chars) < 4) {
            return false;
        }

        $asc = $desc = true;
        for ($i = 1, $n = count($chars); $i < $n; $i++) {
            $diff = mb_ord($chars[$i]) - mb_ord($chars[$i - 1]);
            if ($diff !== 1)  { $asc  = false; }
            if ($diff !== -1) { $desc = false; }
        }
        return $asc || $desc;
    }
}
