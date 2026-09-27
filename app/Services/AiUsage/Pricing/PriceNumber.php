<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

/**
 * Exact decimal helpers shared by every pricing adapter and the writer.
 *
 * Prices travel as decimal strings end to end so binary float rounding never
 * leaks into the catalog: floats are rendered once (with scientific notation
 * expanded), unit conversions are powers of ten done by moving the decimal
 * point, and column rounding uses integer math.
 */
final class PriceNumber
{
    /**
     * Maximum number of significant whole-number digits a catalog rate may
     * carry (`0` to `9999.9999`).
     */
    private const int MAX_WHOLE_DIGITS = 4;

    /**
     * `9999.9999` scaled by 10^4.
     */
    private const int MAX_SCALED_RATE = 99_999_999;

    /**
     * Fraction digits used when rendering a float. Per-token prices reach
     * 1e-8, so 14 places keep every significant digit without exposing float
     * noise.
     */
    private const int FLOAT_PRECISION = 14;

    /**
     * Normalize an upstream price to a plain non-negative decimal string, or
     * null when it is non-numeric, negative, or non-finite. Integers and
     * decimal strings keep their digits exactly; floats and scientific
     * notation are expanded without exponent or trailing zeros.
     */
    public static function normalize(mixed $value): ?string
    {
        if (is_int($value)) {
            $normalized = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $normalized = self::formatFloat($value);
        } elseif (is_string($value)) {
            $normalized = trim($value);

            if (preg_match('/^\+?(\d+(\.\d*)?|\.\d+)e[+-]?\d+$/Di', $normalized) === 1) {
                $normalized = self::formatFloat((float) $normalized);
            }
        } else {
            return null;
        }

        if (preg_match('/^\+?\d+(?:\.\d+)?$/D', $normalized) !== 1) {
            return null;
        }

        return ltrim($normalized, '+');
    }

    /**
     * Move the decimal point of a normalized decimal string by `$places`
     * (positive = right / multiply by 10^n, negative = left / divide by 10^n).
     * Leading whole zeros and trailing fraction zeros are dropped.
     */
    public static function shiftDecimal(string $decimal, int $places): string
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $digits = $whole.$fraction;
        $point = strlen($whole) + $places;

        if ($point <= 0) {
            $digits = str_repeat('0', 1 - $point).$digits;
            $point = 1;
        } elseif ($point > strlen($digits)) {
            $digits .= str_repeat('0', $point - strlen($digits));
        }

        $newWhole = ltrim(substr($digits, 0, $point), '0');
        $newFraction = rtrim(substr($digits, $point), '0');

        return sprintf(
            '%s%s',
            $newWhole === '' ? '0' : $newWhole,
            $newFraction === '' ? '' : sprintf('.%s', $newFraction),
        );
    }

    /**
     * Whether a normalized decimal fits the catalog's whole-number range.
     */
    public static function withinColumnRange(string $decimal): bool
    {
        $whole = ltrim(explode('.', $decimal, 2)[0], '0');

        return strlen($whole) <= self::MAX_WHOLE_DIGITS;
    }

    /**
     * Validate a decimal string as within `0` to `9999.9999`, then round it to
     * exactly four decimal places (half-up) with integer math. Returns null
     * when the value is not a plain decimal or overflows after rounding.
     */
    public static function roundToColumnScale(string $decimal): ?string
    {
        $decimal = trim($decimal);

        if (preg_match('/^\+?(\d+)(?:\.(\d+))?$/D', $decimal, $matches) !== 1) {
            return null;
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($whole) > self::MAX_WHOLE_DIGITS) {
            return null;
        }

        $fraction = $matches[2] ?? '';
        $fivePlaces = str_pad(substr($fraction, 0, 5), 5, '0');

        $scaledToFivePlaces = ((int) $whole * 100_000) + (int) $fivePlaces;
        $scaled = intdiv($scaledToFivePlaces + 5, 10);

        if ($scaled < 0 || $scaled > self::MAX_SCALED_RATE) {
            return null;
        }

        return sprintf('%d.%s', intdiv($scaled, 10_000), str_pad((string) ($scaled % 10_000), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Render a finite, non-negative float as a plain decimal string.
     */
    private static function formatFloat(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.'.self::FLOAT_PRECISION.'F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
