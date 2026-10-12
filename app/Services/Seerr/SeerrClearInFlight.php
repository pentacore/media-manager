<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * One bulk clear per Seerr connection and status at a time. The Requests
 * page claims the slot before it lists or queues anything, so a second clear
 * while one runs is refused with a message instead of being dropped by
 * ClearSeerrRequests' unique lock behind a success toast.
 *
 * The slot is a cache lock with a random owner token: acquiring it is atomic
 * on the shared store, so two submits cannot both win, and refresh() and
 * release() act only while the stored owner still equals the caller's token
 * (an owner-checked compare on Redis and on the array store alike). A job
 * that outlived its slot, or a manual queue:retry of an old job, therefore
 * cannot free a newer clear's slot.
 *
 * The page refreshes the slot right before dispatch(), which is when the
 * job's #[UniqueFor(600)] lock starts. Measured from there, TTL_SECONDS
 * outlives the unique lock however long the listing took, so a crashed worker
 * can only make the page say "already clearing" a minute too long, never let
 * a clear through to be dropped.
 */
final readonly class SeerrClearInFlight
{
    public const int TTL_SECONDS = 660;

    /** Takes the slot and returns its owner token; null when a clear for this connection and status holds it. */
    public static function claim(int $serviceConnectionId, string $status): ?string
    {
        $lock = Cache::lock(self::key($serviceConnectionId, $status), self::TTL_SECONDS);

        return $lock->get() ? $lock->owner() : null;
    }

    /** Restarts the slot's TTL, only while $token still owns it. */
    public static function refresh(int $serviceConnectionId, string $status, string $token): bool
    {
        $lock = Cache::restoreLock(self::key($serviceConnectionId, $status), $token);

        return $lock instanceof Lock && $lock->refresh(self::TTL_SECONDS);
    }

    /** Frees the slot, only while $token still owns it. */
    public static function release(int $serviceConnectionId, string $status, string $token): void
    {
        Cache::restoreLock(self::key($serviceConnectionId, $status), $token)->release();
    }

    public static function key(int $serviceConnectionId, string $status): string
    {
        return sprintf('seerr-clear-in-flight:%d:%s', $serviceConnectionId, $status);
    }
}
