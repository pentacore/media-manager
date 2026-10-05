<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Reads a positive integer id from an ActionRequest payload. A missing key
 * and a present but unusable value fail with different messages, so a
 * malformed payload (an AI proposal, a hand-edited row) says what is wrong
 * instead of claiming the key is absent. Which values pass is unchanged
 * from the `(int) ($payload[$key] ?? 0) > 0` checks it replaces.
 */
final class PayloadInt
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException
     */
    public static function required(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            throw new InvalidArgumentException(sprintf('%s is required', $key));
        }

        $integer = (int) $value;

        if ($integer <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer', $key));
        }

        return $integer;
    }
}
