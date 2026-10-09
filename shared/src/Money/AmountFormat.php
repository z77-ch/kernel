<?php
namespace Z77\Shared\Money;

/**
 * How an amount is written on a backend screen — the ONE place (Rule 8):
 * Swiss grouping with an apostrophe, two decimals, a leading minus, no
 * currency (every column is the base currency). String work on
 * `Money::toDecimal()` — no float anywhere near an amount.
 *
 * Lived in module-financial (`Ui\AmountFormat`) until a second module
 * showed amounts (module-debtor's document screens, P3 part 3) — moved here
 * next to `Money`, so neither module requires the other for it.
 */
final class AmountFormat
{
    /** `1234567.5` minor → «1'234'567.50»; an empty string for null (a cell without an amount). */
    public static function of(?Money $money): string
    {
        if ($money === null) {
            return '';
        }
        [$units, $fraction] = explode('.', ltrim($money->toDecimal(), '-'));
        $grouped = strrev(implode("'", str_split(strrev($units), 3)));

        return ($money->isNegative() ? '−' : '') . $grouped . '.' . $fraction;
    }

    /** A form value: plain decimal without grouping, empty for a zero side (the other side carries the amount). */
    public static function field(Money $money): string
    {
        return $money->isZero() ? '' : $money->toDecimal();
    }
}
