<?php

namespace App\Services\MediaReplacement;

/**
 * Canonical distributed lock for one automatic subtitle-check target.
 */
final readonly class AutomaticSubtitleCheckLock
{
    /**
     * The candidate search can spend up to 120 seconds in the Arr API. Keep the
     * admission lease beyond that whole search-and-dispatch path.
     */
    public const int TTL_SECONDS = 300;

    public static function key(string $autoCheckKey): string
    {
        return 'automatic-subtitle-check:'.hash('sha256', $autoCheckKey);
    }
}
