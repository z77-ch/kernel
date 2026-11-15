<?php

namespace Z77\Shared\Payment;

/**
 * The structured creditor reference «RF» (ISO 11649, reference type SCOR of
 * the Swiss QR-bill) — pure functions. `RF`, two check digits, then 1 to 21
 * letters or digits (5–25 characters in all); the check digits are ISO 7064
 * MOD 97-10 exactly like an IBAN's ({@see Mod97}). It goes with a NORMAL IBAN,
 * never with a QR-IBAN (that one carries a {@see QrReference}).
 *
 * z77 does not print SCOR (owner 2026-09-23: QRR or NON only); a supplier's
 * invoice may carry one, and module-creditor checks what it reads
 * (creditor plan §2.5).
 */
final class ScorReference
{
    public const MIN_LENGTH = 5;
    public const MAX_LENGTH = 25;

    /** Upper-cased, every space removed — how a reference is stored and compared. */
    public static function normalize(string $reference): string
    {
        return (string) preg_replace('/\s+/', '', mb_strtoupper(trim($reference)));
    }

    /** `RF` + two check digits + 1–21 letters / digits, and MOD 97-10 holds. Case and spaces are ignored. */
    public static function isValid(string $reference): bool
    {
        $reference = self::normalize($reference);
        if (!preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $reference)) {
            return false;
        }

        return Mod97::holds($reference);
    }

    /** «RF18 5390 0754 7034» — grouped in fours, as the payment part prints it. */
    public static function format(string $reference): string
    {
        return trim(chunk_split(self::normalize($reference), 4, ' '));
    }
}
