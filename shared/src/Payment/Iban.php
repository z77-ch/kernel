<?php

namespace Z77\Shared\Payment;

/**
 * What an IBAN is, checked rather than assumed — pure functions, no state,
 * no storage. Shared since 2026-11-15 (creditor plan §2.5): module-debtor's
 * payment targets and bank import, and module-creditor's supplier IBANs ask
 * the same questions; it lived in module-debtor (`Services\Iban`) until the
 * second module needed it.
 *
 * Three questions, and they are deliberately separate:
 *
 *   - {@see isWellFormed()} — shape only: two country letters, two check
 *     digits, then letters and digits, 15 to 34 characters (the ISO 13616
 *     range), and the exact length the country registry gives when we know
 *     it (CH and LI: 21);
 *   - {@see hasValidCheckDigits()} — the ISO 7064 MOD-97-10 checksum, which
 *     is what catches a transposed pair of digits ({@see Mod97});
 *   - {@see isQrIban()} — a Swiss QR-IBAN, recognised by its institution
 *     identification (IID, positions 5–9) lying in **30000–31999**, the
 *     range SIX reserved for QR-IBANs. A QR-IBAN ALWAYS carries a QR
 *     reference (QRR, {@see QrReference}); a normal IBAN carries none or a
 *     creditor reference (SCOR, {@see ScorReference}).
 *
 * Whether a CH / LI IBAN is REQUIRED is the caller's rule
 * ({@see isSwissArea()}): a QR-bill the framework prints names only one, a
 * supplier's invoice may name a foreign one.
 */
final class Iban
{
    /** The IID range SIX reserved for QR-IBANs (inclusive). */
    public const QR_IID_MIN = 30000;
    public const QR_IID_MAX = 31999;

    /** The longest IBAN (ISO 13616) — the column length of a stored, normalised IBAN. */
    public const MAX_LENGTH = 34;

    /** Country code → the exact IBAN length, where it is known and checked. */
    private const LENGTHS = ['CH' => 21, 'LI' => 21];

    /** Upper-cased, every space and non-alphanumeric character removed — how an IBAN is stored and compared. */
    public static function normalize(string $iban): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($iban)));
    }

    /** «CH93 0076 2011 6238 5295 7» — grouped in fours for a screen or a document. */
    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }

    /** Shape only — the checksum is {@see hasValidCheckDigits()}. */
    public static function isWellFormed(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $expected = self::LENGTHS[substr($iban, 0, 2)] ?? null;

        return $expected === null || strlen($iban) === $expected;
    }

    /** ISO 7064 MOD-97-10: move the first four characters to the end, letters → 10..35, remainder must be 1. */
    public static function hasValidCheckDigits(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }

        return Mod97::holds($iban);
    }

    /** A CH or LI IBAN — the only ones a QR-bill may name as the creditor account. */
    public static function isSwissArea(string $iban): bool
    {
        return in_array(substr(self::normalize($iban), 0, 2), ['CH', 'LI'], true);
    }

    /**
     * The institution identification (IID), positions 5–9 of a CH / LI
     * IBAN. Null for any other country or a shape that has no IID.
     */
    public static function iid(string $iban): ?int
    {
        $iban = self::normalize($iban);
        if (!self::isSwissArea($iban) || !preg_match('/^[A-Z]{2}\d{7}/', $iban)) {
            return null;
        }

        return (int) substr($iban, 4, 5);
    }

    /** A QR-IBAN: a CH / LI IBAN whose IID lies in 30000–31999 (it carries a QR reference, QRR). */
    public static function isQrIban(string $iban): bool
    {
        $iid = self::iid($iban);

        return $iid !== null && $iid >= self::QR_IID_MIN && $iid <= self::QR_IID_MAX;
    }
}
