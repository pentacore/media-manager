<?php

declare(strict_types=1);

namespace App\Ai\Benchmark;

final class ToolArguments
{
    /** A plain decimal: an optional minus, no leading zeros, no exponent. */
    private const string PLAIN_DECIMAL = '/^-?(0|[1-9]\d*)(?:\.(\d+))?$/';

    /** Beyond this a float no longer holds every digit, so the string stays a string. */
    private const int MAX_SIGNIFICANT_DIGITS = 15;

    /**
     * Canonical form for matching: keys sorted recursively; strings trimmed,
     * whitespace-collapsed and lower-cased; plain decimal strings that
     * round-trip exactly become numbers ("5" → 5, "2.50" → 2.5) while
     * identifier-like ones stay strings ("007", "1e3", "+5", a 19-digit id);
     * whole floats become integers; null values and empty-string values are
     * dropped (null ≡ missing); lists keep their order.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    public static function normalise(array $arguments): array
    {
        if (array_is_list($arguments)) {
            return array_map(self::normaliseValue(...), $arguments);
        }

        $normalised = [];

        foreach ($arguments as $key => $value) {
            $value = self::normaliseValue($value);

            if ($value === null || $value === '') {
                continue;
            }

            $normalised[$key] = $value;
        }

        ksort($normalised);

        return $normalised;
    }

    /**
     * Whether two argument sets are the same call after normalisation.
     *
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     */
    public static function same(array $a, array $b): bool
    {
        return self::normalise($a) === self::normalise($b);
    }

    /**
     * Whether $actual contains every key/value of $expected after
     * normalisation (recursive for objects; lists must be equal).
     *
     * @param  array<array-key, mixed>  $actual
     * @param  array<array-key, mixed>  $expected
     */
    public static function contains(array $actual, array $expected): bool
    {
        return self::isSubset(self::normalise($actual), self::normalise($expected));
    }

    /**
     * @param  array<array-key, mixed>  $actual
     * @param  array<array-key, mixed>  $expected
     */
    private static function isSubset(array $actual, array $expected): bool
    {
        if ($expected === []) {
            return true;
        }

        if (array_is_list($expected)) {
            return $actual === $expected;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value) && is_array($actual[$key])) {
                if (! self::isSubset($actual[$key], $value)) {
                    return false;
                }

                continue;
            }

            if ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private static function normaliseValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::normalise($value);
        }

        if (is_string($value)) {
            $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

            return self::exactNumber($value) ?? $value;
        }

        if (is_float($value) || is_int($value)) {
            return self::normaliseNumber($value);
        }

        return $value;
    }

    /**
     * The number a plain decimal string of at most 15 significant digits
     * stands for (converting it loses nothing); null for any other string.
     */
    private static function exactNumber(string $value): int|float|null
    {
        if (preg_match(self::PLAIN_DECIMAL, $value, $matches) !== 1) {
            return null;
        }

        $fraction = rtrim($matches[2] ?? '', '0');

        if (strlen(ltrim($matches[1].$fraction, '0')) > self::MAX_SIGNIFICANT_DIGITS) {
            return null;
        }

        return $fraction === '' ? (int) $value : (float) $value;
    }

    private static function normaliseNumber(int|float $number): int|float
    {
        if (is_float($number) && is_finite($number) && floor($number) === $number && abs($number) < PHP_INT_MAX) {
            return (int) $number;
        }

        return $number;
    }
}
