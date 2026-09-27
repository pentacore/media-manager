<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

/**
 * Model-identifier rules shared by every pricing adapter so all sources agree
 * on what a storable identifier is and which dated snapshots are redundant.
 */
final class PricingModelIds
{
    /**
     * Normalize an identifier for the `string`/VARCHAR(255) catalog columns:
     * surrounding whitespace is trimmed; empty values, ASCII control
     * characters, and values beyond 255 characters are rejected.
     */
    public static function normalize(string $identifier): ?string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            return null;
        }

        $identifier = trim($identifier);

        if ($identifier === '' || strlen($identifier) > 255 || mb_strlen($identifier) > 255) {
            return null;
        }

        return $identifier;
    }

    /**
     * Whether the identifier is a date-suffixed snapshot (`-YYYYMMDD` or
     * `-YYYY-MM-DD`) of a base model present in the same provider slice. Only
     * real calendar dates count, so short numeric version suffixes (for example
     * `-0709`) are never treated as dates, and a dated model without a base
     * sibling is kept.
     *
     * @param  array<string, true>  $sliceIds
     */
    public static function isDatedVariantOfKnownBase(string $modelId, array $sliceIds): bool
    {
        if (preg_match('/^(.+)-(\d{4})-?(\d{2})-?(\d{2})$/D', $modelId, $matches) !== 1) {
            return false;
        }

        if (! checkdate((int) $matches[3], (int) $matches[4], (int) $matches[2])) {
            return false;
        }

        return isset($sliceIds[$matches[1]]);
    }
}
