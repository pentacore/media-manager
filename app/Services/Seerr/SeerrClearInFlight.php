<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use Illuminate\Support\Facades\Cache;

/**
 * One bulk clear per Seerr connection and status at a time. The Requests
 * page claims the slot before it lists or queues anything, so a second clear
 * while one runs is refused with a message instead of being dropped by
 * ClearSeerrRequests' unique lock behind a success toast. Cache::add() is
 * atomic on the shared store, so two submits cannot both win. The job
 * releases the slot when it finishes or fails; the TTL outlives the job's
 * #[UniqueFor(600)], so a crashed worker can only make the page say "already
 * clearing" a minute too long, never let a dropped clear through.
 */
final readonly class SeerrClearInFlight
{
    public const int TTL_SECONDS = 660;

    /** Takes the slot; false when a clear for this connection and status holds it. */
    public static function claim(int $serviceConnectionId, string $status): bool
    {
        return Cache::add(self::key($serviceConnectionId, $status), true, self::TTL_SECONDS);
    }

    public static function release(int $serviceConnectionId, string $status): void
    {
        Cache::forget(self::key($serviceConnectionId, $status));
    }

    public static function key(int $serviceConnectionId, string $status): string
    {
        return sprintf('seerr-clear-in-flight:%d:%s', $serviceConnectionId, $status);
    }
}
