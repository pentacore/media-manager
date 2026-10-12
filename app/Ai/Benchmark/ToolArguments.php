<?php

declare(strict_types=1);

namespace App\Ai\Benchmark;

final class ToolArguments
{
    /**
     * Canonical form for matching: keys sorted recursively; strings trimmed,
     * whitespace-collapsed and lower-cased; numeric strings become numbers
     * ("5" → 5, "2.50" → 2.5) and whole floats become integers; null values
     * and empty-string values are dropped (null ≡ missing); lists keep their order.
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

            return is_numeric($value) ? self::normaliseNumber($value + 0) : $value;
        }

        if (is_float($value) || is_int($value)) {
            return self::normaliseNumber($value);
        }

        return $value;
    }

    private static function normaliseNumber(int|float $number): int|float
    {
        if (is_float($number) && is_finite($number) && floor($number) === $number && abs($number) < PHP_INT_MAX) {
            return (int) $number;
        }

        return $number;
    }
}
