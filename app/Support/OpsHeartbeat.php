<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\QueueLane;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Liveness timestamps for the scheduler and each queue lane, kept in the
 * default cache store (Valkey in production) so the container healthchecks
 * and /metrics can read them. Unrelated to the browser presence heartbeat.
 */
final class OpsHeartbeat
{
    public const string SCHEDULER = 'scheduler';

    /**
     * When the scheduler last came back after a gap (or first ran). Not a liveness component: it is only
     * written on a resume, so ops:check-heartbeat must never judge its age.
     */
    public const string SCHEDULER_RESUMED = 'scheduler-resumed';

    public const string CACHE_PREFIX = 'ops-heartbeat:';

    public static function forLane(QueueLane $queueLane): string
    {
        return sprintf('queue:%s', $queueLane->value);
    }

    /**
     * @return list<string>
     */
    public static function components(): array
    {
        return [self::SCHEDULER, ...array_map(self::forLane(...), QueueLane::cases())];
    }

    public static function record(string $component): void
    {
        Cache::forever(self::CACHE_PREFIX.$component, now()->getTimestamp());
    }

    /**
     * Records the scheduler heartbeat, first marking a resume when the previous one is missing or older than
     * $gapSeconds: the platform was down, and clocks that measure silence restart from this moment.
     */
    public static function recordScheduler(int $gapSeconds): void
    {
        $age = self::ageInSeconds(self::SCHEDULER);

        if ($age === null || $age > $gapSeconds) {
            self::record(self::SCHEDULER_RESUMED);
        }

        self::record(self::SCHEDULER);
    }

    /**
     * When the component last reported, or null if it never has.
     */
    public static function recordedAt(string $component): ?CarbonImmutable
    {
        $recordedAt = self::timestamp($component);

        return $recordedAt === null ? null : CarbonImmutable::createFromTimestamp($recordedAt, now()->getTimezone());
    }

    /**
     * Seconds since the component last reported, or null if it never has.
     */
    public static function ageInSeconds(string $component): ?int
    {
        $recordedAt = self::timestamp($component);

        if ($recordedAt === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - $recordedAt);
    }

    /**
     * Components that are missing or older than $maxAgeSeconds, with their age.
     *
     * @param  list<string>  $components
     * @return array<string, int|null>
     */
    public static function stale(array $components, int $maxAgeSeconds): array
    {
        $stale = [];

        foreach ($components as $component) {
            $age = self::ageInSeconds($component);

            if ($age === null || $age > $maxAgeSeconds) {
                $stale[$component] = $age;
            }
        }

        return $stale;
    }

    private static function timestamp(string $component): ?int
    {
        $recordedAt = Cache::get(self::CACHE_PREFIX.$component);

        // The Redis store hands numbers back as numeric strings.
        return is_numeric($recordedAt) ? (int) $recordedAt : null;
    }
}
