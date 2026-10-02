<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Models\ServiceConnection;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises a member's cancel (live status read, then DELETE) with the
 * approve and decline MediaManager sends for the same Seerr request, so a
 * member cannot cancel a request that was approved a moment earlier. Other
 * writes (the console's delete, retry and edit, cleanup of available media,
 * the bulk clear) are deliberately unlocked: none of them can turn a
 * member's cancel into the wrong outcome.
 *
 * Approvals made inside Seerr's own UI bypass this lock. That residual race
 * is accepted: it needs an admin acting in Seerr within the cancel's one
 * read-then-delete window, and the member can simply request again.
 */
final readonly class SeerrRequestLock
{
    /**
     * Outlives a cancel's worst case: the live read retries (3 × 10 s plus
     * backoff) and the non-retrying DELETE (10 s) — about 42 s — so the lock
     * never lapses mid-cancel. Well under Octane's 150 s request ceiling.
     */
    public const int TTL_SECONDS = 60;

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
