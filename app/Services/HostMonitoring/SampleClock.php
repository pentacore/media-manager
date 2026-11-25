<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The clock the sample tables run on. `recorded_at` is a `timestamptz`, so a sample is an instant and the two
 * 02:00–03:00 hours of a DST fall-back night are different rows. Postgres reads a time without an offset in the
 * session time zone (UTC), not the app timezone, so every bound passed to a query on `recorded_at` carries its offset
 * ({@see self::bind()}).
 *
 * Buckets of up to an hour are cut on instants from a fixed origin, midnight 2000-01-01 on the app timezone, so minute,
 * five-minute and hourly buckets start on the app timezone's whole minutes and hours and the two fall-back hours stay
 * apart. Longer buckets are cut on the app timezone's wall clock instead, so a 30-day chart's six-hour buckets start
 * at 00, 06, 12 and 18 local time all year: an instant grid from a winter-time origin would start them an hour later
 * in summer. A wall-clock bucket that spans a DST change is an hour longer or shorter in real time (the six-hour
 * bucket holding a fall-back night covers seven hours).
 */
final readonly class SampleClock
{
    /** `date_bin` over `recorded_at`; binds the bucket seconds, then {@see self::origin()}. */
    public const string BUCKET = 'date_bin(make_interval(secs => CAST(? AS integer)), recorded_at, CAST(? AS timestamptz))';

    /** `date_bin` over `recorded_at`'s app-timezone wall clock; binds the bucket seconds, then the app timezone. */
    private const string WALL_CLOCK_BUCKET = "date_bin(make_interval(secs => CAST(? AS integer)), recorded_at AT TIME ZONE CAST(? AS text), TIMESTAMP '2000-01-01')";

    /** The longest bucket cut on instants; one hour keeps the two fall-back hours apart. */
    private const int LONGEST_INSTANT_BUCKET = 3600;

    public static function origin(): CarbonImmutable
    {
        return CarbonImmutable::parse('2000-01-01 00:00:00', self::timezone());
    }

    /**
     * The SQL expression for the start of a `recorded_at` bucket and its bindings. Read its value back with
     * {@see self::instant()}.
     *
     * @return array{literal-string, list<int|string>}
     */
    public static function bucket(int $seconds): array
    {
        return self::onWallClock($seconds)
            ? [self::WALL_CLOCK_BUCKET, [$seconds, self::timezone()]]
            : [self::BUCKET, [$seconds, self::bind(self::origin())]];
    }

    /**
     * A bucket start as Postgres returns it ({@see self::bucket()}) as a Unix timestamp.
     */
    public static function instant(mixed $bucket): int
    {
        // An instant bucket carries its offset; a wall-clock one is read on the app timezone, as starts() does.
        return CarbonImmutable::parse((string) $bucket, self::timezone())->getTimestamp();
    }

    /**
     * The start of the bucket holding the moment, in the app timezone.
     */
    public static function floor(CarbonInterface $moment, int $seconds): CarbonImmutable
    {
        if (self::onWallClock($seconds)) {
            $wallClock = self::wallClock($moment);
            $sinceOrigin = $wallClock->getTimestamp() - CarbonImmutable::parse('2000-01-01 00:00:00', 'UTC')->getTimestamp();

            return self::fromWallClock($wallClock->subSeconds($sinceOrigin % $seconds));
        }

        $sinceOrigin = $moment->getTimestamp() - self::origin()->getTimestamp();

        return CarbonImmutable::createFromTimestamp($moment->getTimestamp() - $sinceOrigin % $seconds, self::timezone());
    }

    /**
     * The start of the bucket holding the moment and of the given number of buckets before it, oldest first, as Unix
     * timestamps.
     *
     * @return non-empty-list<int>
     */
    public static function starts(CarbonInterface $moment, int $seconds, int $earlier): array
    {
        $current = self::floor($moment, $seconds);

        if (! self::onWallClock($seconds)) {
            return array_map(
                static fn (int $buckets): int => $current->getTimestamp() - $buckets * $seconds,
                range($earlier, 0),
            );
        }

        $wallClock = self::wallClock($current);

        return array_map(
            static fn (int $buckets): int => self::fromWallClock($wallClock->subSeconds($buckets * $seconds))->getTimestamp(),
            range($earlier, 0),
        );
    }

    /**
     * A query bound for `recorded_at` that keeps its offset.
     */
    public static function bind(CarbonInterface $moment): string
    {
        return $moment->toIso8601String();
    }

    private static function onWallClock(int $seconds): bool
    {
        return $seconds > self::LONGEST_INSTANT_BUCKET;
    }

    /**
     * The moment's app-timezone wall clock read as UTC, so wall-clock arithmetic skips or repeats no DST hour.
     */
    private static function wallClock(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment->setTimezone(self::timezone())->toDateTimeString(), 'UTC');
    }

    private static function fromWallClock(CarbonImmutable $wallClock): CarbonImmutable
    {
        return CarbonImmutable::parse($wallClock->toDateTimeString(), self::timezone());
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
