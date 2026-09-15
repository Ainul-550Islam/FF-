<?php

namespace App\Support;

use DomainException;

/**
 * Integer minor-unit money handling (BDT poisha, 100 minor units per taka).
 *
 * No floating-point arithmetic is ever used for monetary values: decimals are
 * converted to/from integer minor units via string math, so amounts like
 * "123.45" are exactly 12345 poisha.
 */
final class Money
{
    /**
     * Minor units per major unit (BDT: 100 poisha per taka).
     */
    public const MINOR_UNITS = 100;

    /**
     * Convert a decimal amount ("123.45", 100, 99.9, "0") into integer minor
     * units (poisha). Negative amounts are rejected — financial operations in
     * FF Arena are never negative.
     */
    public static function toMinor(mixed $amount): int
    {
        $string = trim((string) $amount);

        if ($string === '' || $string === '-') {
            throw new DomainException('Invalid amount.');
        }

        $negative = str_starts_with($string, '-');
        if ($negative) {
            $string = substr($string, 1);
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $string)) {
            throw new DomainException('Invalid amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');

        // Round a 3rd decimal place half-up; keep only two fraction digits.
        if (strlen($fraction) > 2) {
            $third = (int) $fraction[2];
            $fraction = substr($fraction, 0, 2);
            if ($third >= 5) {
                $fraction = str_pad((string) ((int) $fraction + 1), 2, '0', STR_PAD_LEFT);
                if ((int) $fraction >= 100) {
                    $whole = (string) ((int) $whole + 1);
                    $fraction = '00';
                }
            }
        }

        $fraction = str_pad($fraction, 2, '0');

        $minor = ((int) $whole * self::MINOR_UNITS) + (int) $fraction;

        if ($negative) {
            throw new DomainException('Negative amounts are not allowed.');
        }

        return $minor;
    }

    /**
     * Convert integer minor units into a 2-decimal string ("12345" → "123.45").
     */
    public static function toDecimal(int $minor): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);

        $whole = intdiv($minor, self::MINOR_UNITS);
        $fraction = $minor % self::MINOR_UNITS;

        return ($negative ? '-' : '').$whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format minor units for display (e.g. "৳123.45").
     */
    public static function formatMinor(int $minor): string
    {
        return '৳'.number_format((float) self::toDecimal($minor), 2);
    }

    /**
     * Convert a percentage string ("50", "33.33", "100") into integer basis
     * points (1/100th of a percent): "33.33" → 3333, "100" → 10000.
     *
     * Reuses the exact integer string math of toMinor() (a percent has two
     * decimal places, so 1% = 100 basis points). No floating-point is used.
     */
    public static function toBasisPoints(mixed $percent): int
    {
        return self::toMinor($percent);
    }

    /**
     * Convert integer basis points back to a 2-decimal percent string
     * (3333 → "33.33").
     */
    public static function basisPointsToPercent(int $basisPoints): string
    {
        return self::toDecimal($basisPoints);
    }
}
