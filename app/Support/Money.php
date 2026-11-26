<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored everywhere as integer nano-dollars (1 USD = 1_000_000_000 nanos).
 *
 * Pixelforge reports costs with nine decimal places, so nanos represent its numbers
 * exactly and sums never drift the way floats would.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /** Parse a non-negative decimal string such as "0.169080000" or "12.50" into nanos, exactly. */
    public static function decimalToNanos(string $decimal): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', trim($decimal), $m)) {
            throw new InvalidArgumentException("Not a decimal amount with at most 9 places: [{$decimal}]");
        }

        $fraction = str_pad($m[2] ?? '', 9, '0');

        return (int) $m[1] * self::NANOS_PER_DOLLAR + (int) $fraction;
    }

    /** Exact decimal string with nine places, e.g. 169080000 => "0.169080000". */
    public static function nanosToDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        return $sign.intdiv($abs, self::NANOS_PER_DOLLAR).'.'.str_pad((string) ($abs % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /** Divide and round up (towards +infinity) for non-negative numerators. */
    public static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }

    /** Apply a percentage markup, rounding up to the next nano so we never undercharge. */
    public static function withMarkup(int $nanos, int $markupPercent): int
    {
        return self::ceilDiv($nanos * (100 + $markupPercent), 100);
    }
}
