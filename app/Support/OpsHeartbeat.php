<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\QueueLane;
use Illuminate\Support\Facades\Cache;

/**
 * Liveness timestamps for the scheduler and each queue lane, kept in the
 * default cache store (Valkey in production) so the container healthchecks
 * and /metrics can read them. Unrelated to the browser presence heartbeat.
 */
final class OpsHeartbeat
{
    public const string SCHEDULER = 'scheduler';

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
     * Seconds since the component last reported, or null if it never has.
     */
    public static function ageInSeconds(string $component): ?int
    {
        $recordedAt = Cache::get(self::CACHE_PREFIX.$component);

        // The Redis store hands numbers back as numeric strings.
        if (! is_numeric($recordedAt)) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $recordedAt);
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
}
