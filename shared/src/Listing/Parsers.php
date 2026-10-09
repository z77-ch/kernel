<?php

namespace Z77\Shared\Listing;

use Z77\Shared\Money\Money;

/**
 * The strict readers of a column search value (listing.md). Each returns a
 * closure `string → mixed|null`: the parsed value, or null when the text
 * cannot be read — the list then marks the field invalid and ignores it,
 * never guesses. Shared so every list reads «11.2032» or «1'250.50» the
 * same way (the journal's and the invoice list's parsers, unified
 * 2026-10-08).
 */
final class Parsers
{
    /** «17» → 17; up to $maxDigits digits, nothing else. */
    public static function integer(int $maxDigits = 9): \Closure
    {
        return static fn(string $value): ?int => preg_match('/^\d{1,' . $maxDigits . '}$/', $value) ? (int) $value : null;
    }

    /**
     * «15.11.2032» / «2032-11-15» → that day, «11.2032» → the month,
     * «2032» → the calendar year: `[from, to]` as `Y-m-d`, inclusive.
     */
    public static function dateRange(): \Closure
    {
        return static function (string $value): ?array {
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                    ? array_fill(0, 2, sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])) : null;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
                return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? [$value, $value] : null;
            }
            if (preg_match('/^(\d{1,2})\.(\d{4})$/', $value, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
                $first = sprintf('%04d-%02d-01', $m[2], $m[1]);

                return [$first, (new \DateTimeImmutable($first))->format('Y-m-t')];
            }
            if (preg_match('/^\d{4}$/', $value)) {
                return [$value . '-01-01', $value . '-12-31'];
            }

            return null;
        };
    }

    /**
     * «1'250.50», «1250,5», «-30» → the decimal string `Money` stores
     * («1250.50»). Eleven digits at most: a longer number would overflow
     * `Money` and must read as invalid, never as a 500.
     */
    public static function amount(string $currency): \Closure
    {
        return static function (string $value) use ($currency): ?string {
            $normalized = str_replace(["'", '’', ' '], '', str_replace(',', '.', $value));
            if (!preg_match('/^-?\d{1,11}(\.\d{1,2})?$/', $normalized)) {
                return null;
            }
            try {
                return Money::fromDecimal($normalized, $currency)->toDecimal();
            } catch (\InvalidArgumentException | \OverflowException) {
                return null;
            }
        };
    }

    /** The text as typed — never invalid; a LIKE search of the repository. */
    public static function text(): \Closure
    {
        return static fn(string $value): string => $value;
    }
}
