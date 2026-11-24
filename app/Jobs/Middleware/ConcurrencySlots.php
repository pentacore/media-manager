<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Slot locks shared by the benchmark job middleware: at most SLOTS jobs of
 * one group run at a time, each holding one slot lock for its lifetime. A
 * job finding every slot taken goes back on the queue. The lock TTL outlives
 * the jobs' timeout, so a killed worker frees its slot on its own.
 */
final readonly class ConcurrencySlots
{
    public const int SLOTS = 2;

    public const int RETRY_AFTER_SECONDS = 15;

    private const int LOCK_SECONDS = 300;

    /**
     * Runs the job on the group's first free slot, or calls $whenFull with
     * the retry delay when every slot is taken (it releases the job).
     *
     * @param  Closure(): mixed  $run
     * @param  Closure(int): void  $whenFull
     */
    public static function run(string $group, Closure $run, Closure $whenFull): mixed
    {
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $lock = Cache::lock(sprintf('%s:slot:%d', $group, $slot), self::LOCK_SECONDS);

            if (! $lock->get()) {
                continue;
            }

            try {
                return $run();
            } finally {
                $lock->release();
            }
        }

        $whenFull(self::RETRY_AFTER_SECONDS);

        return null;
    }
}
