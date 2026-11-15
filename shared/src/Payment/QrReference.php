<?php

namespace Z77\Shared\Payment;

/**
 * The QR reference (QRR) of a Swiss QR-bill — pure functions over the
 * STANDARD (SIX, Swiss Implementation Guidelines QR-bill v2.x, «RmtInf /
 * Ref»): exactly 27 digits — 26 digits of payload and a check digit computed
 * MODULO 10 RECURSIVE over them (the algorithm of the former ESR reference,
 * table 0 9 4 6 8 2 7 1 3 5). A reference of zeros only is not valid. A QRR
 * goes with a QR-IBAN ({@see Iban::isQrIban()}).
 *
 * Shared since 2026-11-15 (creditor plan §2.5): module-debtor builds and reads
 * its own references, module-creditor checks the ones a supplier prints. What
 * the 26 payload digits MEAN is the issuer's business, not this class's — the
 * debtor's layout (customer number, document number) is
 * `Z77\Module\Debtor\Invoicing\QrReferenceLayout`.
 */
final class QrReference
{
    public const LENGTH = 27;

    /** The payload digits in front of the check digit. */
    public const PAYLOAD_LENGTH = 26;

    /** The recursive modulo-10 table (SIX / former ESR). */
    private const TABLE = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];

    /**
     * The 27-digit reference of a 26-digit payload — the payload with its check digit appended.
     *
     * @throws \InvalidArgumentException the payload is not 26 digits
     */
    public static function withCheckDigit(string $payload): string
    {
        if (!preg_match('/^\d{' . self::PAYLOAD_LENGTH . '}$/', $payload)) {
            throw new \InvalidArgumentException('A QR reference payload is exactly ' . self::PAYLOAD_LENGTH . ' digits');
        }

        return $payload . self::checkDigit($payload);
    }

    /** The check digit (modulo 10 recursive) of a digit string. */
    public static function checkDigit(string $digits): int
    {
        if (!preg_match('/^\d+$/', $digits)) {
            throw new \InvalidArgumentException('The QR check digit is computed over digits only');
        }
        $carry = 0;
        foreach (str_split($digits) as $digit) {
            $carry = self::TABLE[($carry + (int) $digit) % 10];
        }

        return (10 - $carry) % 10;
    }

    /** Spaces removed — how a reference typed or read from a document is stored and compared. */
    public static function normalize(string $reference): string
    {
        return (string) preg_replace('/\s+/', '', trim($reference));
    }

    /** 27 digits, not all zero, the last one the check digit of the first 26. */
    public static function isValid(string $reference): bool
    {
        if (!preg_match('/^\d{' . self::LENGTH . '}$/', $reference) || trim($reference, '0') === '') {
            return false;
        }

        return self::checkDigit(substr($reference, 0, -1)) === (int) substr($reference, -1);
    }

    /** «21 00000 00003 13947 14300 09017» — blocks of five from the right, as the payment part prints it. */
    public static function format(string $reference): string
    {
        $head = strlen($reference) % 5;
        $out  = $head > 0 ? [substr($reference, 0, $head)] : [];

        return implode(' ', array_merge($out, str_split(substr($reference, $head), 5)));
    }
}
