<?php

namespace Z77\Shared\Money;

/**
 * An amount of money as integer minor units (Rappen, cents) plus an ISO 4217
 * currency code. Immutable. ADR-042.
 *
 * There is no float anywhere in this class, on purpose: wdv-6.2.2 computed
 * money in floats and compared them strictly, and every one of its money bugs
 * came from that. A float handed to any entry point is a TypeError. The kernel
 * runs without `strict_types`, where PHP would silently coerce a float into an
 * `int` parameter before the method sees it — which is why the numeric entry
 * points take `mixed` and check the type themselves.
 *
 * The scale is fixed at two decimals. Every currency the installations use
 * (CHF, EUR, USD) has two; a zero- or three-decimal currency would need a
 * scale field, which nothing needs today.
 *
 * Rounding is always half away from zero (commercial rounding): 0.005 → 0.01,
 * −0.005 → −0.01.
 */
final class Money
{
    public const SCALE = 2;

    /** Longest decimal fraction accepted as a factor — keeps the ratio inside int64. */
    private const MAX_FACTOR_DECIMALS = 9;

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    /** @param int $minor amount in minor units (1250 = 12.50) */
    public static function of(mixed $minor, string $currency): self
    {
        return new self(self::requireInt($minor, 'minor'), self::requireCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::requireCurrency($currency));
    }

    /**
     * Parse a decimal string as it comes from a DECIMAL column, a form or an
     * import: "12.5", "-0.05", "1200". More than two decimals is refused
     * rather than rounded — a third decimal means the source was not money.
     */
    public static function fromDecimal(string $decimal, string $currency): self
    {
        $decimal = trim($decimal);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,' . self::SCALE . '}))?$/', $decimal, $m)) {
            throw new \InvalidArgumentException("Not a money amount with at most " . self::SCALE . " decimals: '{$decimal}'");
        }

        $units    = self::checkedMultiply((int) $m[2], 10 ** self::SCALE);
        $fraction = (int) str_pad($m[3] ?? '', self::SCALE, '0');
        $minor    = self::checkedAdd($units, $fraction);

        return new self($m[1] === '-' ? -$minor : $minor, self::requireCurrency($currency));
    }

    /** "12.50", "-0.05" — the form a DECIMAL(15,2) column takes. */
    public function toDecimal(): string
    {
        $abs  = abs($this->minor);
        $base = 10 ** self::SCALE;
        $sign = $this->minor < 0 ? '-' : '';

        return $sign . intdiv($abs, $base) . '.' . str_pad((string) ($abs % $base), self::SCALE, '0', STR_PAD_LEFT);
    }

    public function add(self $other): self
    {
        $this->requireSameCurrency($other);
        return new self(self::checkedAdd($this->minor, $other->minor), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->requireSameCurrency($other);
        return new self(self::checkedAdd($this->minor, -$other->minor), $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    /**
     * Multiply by an integer or by an exact decimal string ("1.5", "0.333").
     * The result is rounded half away from zero to minor units.
     */
    public function multiply(mixed $factor): self
    {
        if (is_int($factor)) {
            return new self(self::checkedMultiply($this->minor, $factor), $this->currency);
        }
        if (!is_string($factor)) {
            throw new \TypeError('Money::multiply() takes int or a decimal string, ' . get_debug_type($factor) . ' given');
        }
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,' . self::MAX_FACTOR_DECIMALS . '}))?$/', trim($factor), $m)) {
            throw new \InvalidArgumentException("Not a decimal factor: '{$factor}'");
        }

        $decimals    = strlen($m[3] ?? '');
        $denominator = 10 ** $decimals;
        $numerator   = self::checkedAdd(self::checkedMultiply((int) $m[2], $denominator), (int) ($m[3] ?? 0));

        return $this->multiplyByRatio($m[1] === '-' ? -$numerator : $numerator, $denominator);
    }

    /**
     * Multiply by numerator / denominator, rounded half away from zero. The
     * building block for percentages: 8.1 % of an amount is
     * multiplyByRatio(810, 10000); the tax contained in a gross amount is
     * multiplyByRatio(810, 10810).
     */
    public function multiplyByRatio(mixed $numerator, mixed $denominator): self
    {
        $numerator   = self::requireInt($numerator, 'numerator');
        $denominator = self::requireInt($denominator, 'denominator');
        if ($denominator === 0) {
            throw new \DivisionByZeroError('Money::multiplyByRatio() with denominator 0');
        }

        return new self(self::divideRounded(self::checkedMultiply($this->minor, $numerator), $denominator), $this->currency);
    }

    /**
     * Split into parts proportional to $ratios without losing or inventing a
     * minor unit: the parts always add up to this amount exactly. Largest
     * remainder method — the units left after the proportional floor go to
     * the parts with the largest remainders, earlier parts first on a tie.
     *
     * @param list<int> $ratios non-negative, at least one > 0
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new \InvalidArgumentException('Money::allocate() needs at least one ratio');
        }
        $total = 0;
        foreach ($ratios as $ratio) {
            $ratio = self::requireInt($ratio, 'ratio');
            if ($ratio < 0) {
                throw new \InvalidArgumentException('Money::allocate() ratios must not be negative');
            }
            $total = self::checkedAdd($total, $ratio);
        }
        if ($total === 0) {
            throw new \InvalidArgumentException('Money::allocate() ratios must not all be zero');
        }

        $abs        = abs($this->minor);
        $shares     = [];
        $remainders = [];
        $allocated  = 0;
        foreach (array_values($ratios) as $i => $ratio) {
            $product        = self::checkedMultiply($abs, $ratio);
            $shares[$i]     = intdiv($product, $total);
            $remainders[$i] = $product % $total;
            $allocated     += $shares[$i];
        }

        // Stable order: largest remainder first, index breaks the tie.
        $order = array_keys($remainders);
        usort($order, fn(int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        for ($left = $abs - $allocated, $k = 0; $left > 0; $left--, $k++) {
            $shares[$order[$k]]++;
        }

        $sign = $this->minor < 0 ? -1 : 1;
        return array_map(fn(int $share) => new self($sign * $share, $this->currency), $shares);
    }

    /**
     * Round to a multiple of $step minor units, half away from zero.
     * roundTo(5) is the Swiss 0.05 rounding of a document total.
     */
    public function roundTo(mixed $step): self
    {
        $step = self::requireInt($step, 'step');
        if ($step <= 0) {
            throw new \InvalidArgumentException('Money::roundTo() step must be positive');
        }

        return new self(self::checkedMultiply(self::divideRounded($this->minor, $step), $step), $this->currency);
    }

    public function compare(self $other): int
    {
        $this->requireSameCurrency($other);
        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function __toString(): string
    {
        return $this->toDecimal() . ' ' . $this->currency;
    }

    private function requireSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }

    private static function requireCurrency(string $currency): string
    {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException("Not an ISO 4217 currency code: '{$currency}'");
        }
        return $currency;
    }

    private static function requireInt(mixed $value, string $name): int
    {
        if (!is_int($value)) {
            throw new \TypeError("Money: {$name} must be int, " . get_debug_type($value) . ' given');
        }
        return $value;
    }

    /** Integer division rounded half away from zero. */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator < 0) {
            $numerator   = -$numerator;
            $denominator = -$denominator;
        }
        $abs      = abs($numerator);
        $quotient = intdiv($abs, $denominator);
        if (2 * ($abs % $denominator) >= $denominator) {
            $quotient++;
        }

        return $numerator < 0 ? -$quotient : $quotient;
    }

    // PHP turns an overflowing int operation into a float; refuse instead of
    // letting a float into the result.
    private static function checkedMultiply(int $a, int $b): int
    {
        $result = $a * $b;
        if (!is_int($result)) {
            throw new \OverflowException('Money: integer overflow in multiplication');
        }
        return $result;
    }

    private static function checkedAdd(int $a, int $b): int
    {
        $result = $a + $b;
        if (!is_int($result)) {
            throw new \OverflowException('Money: integer overflow in addition');
        }
        return $result;
    }
}
