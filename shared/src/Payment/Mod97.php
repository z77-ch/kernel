<?php

namespace Z77\Shared\Payment;

/**
 * ISO 7064 MOD 97-10 — the check of an IBAN (ISO 13616) and of a creditor
 * reference «RF» (ISO 11649): both put their four leading characters (two
 * letters, two check digits) at the end, turn every letter into its number
 * (A = 10 … Z = 35) and require the remainder 1 when the digit string is
 * divided by 97. Computed in chunks on the DIGIT STRING, never as an integer:
 * a 34-character IBAN expands to ~70 digits and would overflow `int`.
 */
final class Mod97
{
    /** True when $code (upper-case A–Z / 0–9, the check digits at positions 3–4) passes MOD 97-10. */
    public static function holds(string $code): bool
    {
        if (!preg_match('/^[A-Z0-9]{5,}$/', $code)) {
            return false;
        }
        $rearranged = substr($code, 4) . substr($code, 0, 4);
        $digits     = '';
        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_digit($character) ? $character : (string) (ord($character) - 55);
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (((string) $remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }
}
