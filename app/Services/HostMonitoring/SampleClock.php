<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The clock the sample tables run on. `recorded_at` is a `timestamptz`, so a sample is an instant and the two
 * 02:00–03:00 hours of a DST fall-back night are different rows. Postgres reads a time without an offset in the
 * session time zone (UTC), not the app timezone, so every bound passed to a query on `recorded_at` carries its offset
 * ({@see self::bind()}). Buckets are cut from a fixed origin, midnight 2000-01-01 on the app timezone, so minute,
 * five-minute and hourly buckets start on the app timezone's whole minutes and hours.
 */
final readonly class SampleClock
{
    /** `date_bin` over `recorded_at`; binds the bucket seconds, then {@see self::origin()}. */
    public const string BUCKET = 'date_bin(make_interval(secs => CAST(? AS integer)), recorded_at, CAST(? AS timestamptz))';

    public static function origin(): CarbonImmutable
    {
        return CarbonImmutable::parse('2000-01-01 00:00:00', (string) config('app.timezone'));
    }

    /**
     * The start of the bucket holding the moment, in the app timezone.
     */
    public static function floor(CarbonInterface $moment, int $seconds): CarbonImmutable
    {
        $sinceOrigin = $moment->getTimestamp() - self::origin()->getTimestamp();

        return CarbonImmutable::createFromTimestamp($moment->getTimestamp() - $sinceOrigin % $seconds, (string) config('app.timezone'));
    }

    /**
     * A query bound for `recorded_at` that keeps its offset.
     */
    public static function bind(CarbonInterface $moment): string
    {
        return $moment->toIso8601String();
    }
}
