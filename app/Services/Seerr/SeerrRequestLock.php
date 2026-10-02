<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Models\ServiceConnection;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises MediaManager's own writes to one Seerr request, so a member's
 * cancel (live status read, then DELETE) cannot interleave with an approve
 * or decline sent from MediaManager — which would let a member cancel a
 * request that was approved a moment earlier.
 *
 * Approvals made inside Seerr's own UI bypass this lock. That residual race
 * is accepted: it needs an admin acting in Seerr within the cancel's one
 * read-then-delete window, and the member can simply request again.
 */
final readonly class SeerrRequestLock
{
    /** Long enough for one live read plus one write at the 10 s client timeout. */
    public const int TTL_SECONDS = 25;

    /** How long a queued action waits for a member's cancel to finish. */
    public const int JOB_WAIT_SECONDS = 10;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws SeerrRequestBusy
     */
    public function run(ServiceConnection $serviceConnection, int $requestId, Closure $callback, int $waitSeconds = 0): mixed
    {
        $lock = Cache::lock(self::key($serviceConnection->id, $requestId), self::TTL_SECONDS);

        try {
            $acquired = $waitSeconds > 0 ? $lock->block($waitSeconds) : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        throw_unless($acquired, SeerrRequestBusy::class, sprintf('Seerr request %d is being changed by another MediaManager action.', $requestId));

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public static function key(int $connectionId, int $requestId): string
    {
        return sprintf('seerr-request:%d:%d', $connectionId, $requestId);
    }
}
