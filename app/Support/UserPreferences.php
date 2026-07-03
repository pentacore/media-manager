<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

/**
 * Per-user display preferences. Stored as JSON on `users.preferences`,
 * shared with the frontend via `SharedUserResource`, and read by the
 * `useDateTime()` composable to format every date/time the UI shows.
 */
final class UserPreferences
{
    /** @var array<int, string>|null */
    private static ?array $cachedTimezones = null;

    public const string TIME_FORMAT_12H = '12h';

    public const string TIME_FORMAT_24H = '24h';

    public const array TIME_FORMATS = [self::TIME_FORMAT_12H, self::TIME_FORMAT_24H];

    public const string DATE_FORMAT_ISO = 'iso';   // 2026-05-03

    public const string DATE_FORMAT_US = 'us';     // 5/3/2026

    public const string DATE_FORMAT_EU = 'eu';     // 03.05.2026

    public const string DATE_FORMAT_LONG = 'long'; // 3 May 2026

    public const array DATE_FORMATS = [self::DATE_FORMAT_ISO, self::DATE_FORMAT_US, self::DATE_FORMAT_EU, self::DATE_FORMAT_LONG];

    /** ISO weekday: 0 = Sunday, 1 = Monday, … 6 = Saturday. */
    public const array WEEK_STARTS = [0, 1, 2, 3, 4, 5, 6];

    /**
     * @return array{
     *     time_format: '12h'|'24h',
     *     date_format: 'iso'|'us'|'eu'|'long',
     *     timezone: string,
     *     first_day_of_week: int,
     *     show_relative_time: bool
     * }
     */
    public static function defaults(): array
    {
        return [
            'time_format' => self::TIME_FORMAT_24H,
            'date_format' => self::DATE_FORMAT_ISO,
            'timezone' => 'UTC',
            'first_day_of_week' => 1,
            'show_relative_time' => true,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array{
     *     time_format: '12h'|'24h',
     *     date_format: 'iso'|'us'|'eu'|'long',
     *     timezone: string,
     *     first_day_of_week: int,
     *     show_relative_time: bool
     * }
     */
    public static function withDefaults(?array $stored): array
    {
        $defaults = self::defaults();

        if ($stored === null) {
            return $defaults;
        }

        $timeFormat = is_string($stored['time_format'] ?? null) && in_array($stored['time_format'], self::TIME_FORMATS, true)
            ? $stored['time_format']
            : $defaults['time_format'];

        $dateFormat = is_string($stored['date_format'] ?? null) && in_array($stored['date_format'], self::DATE_FORMATS, true)
            ? $stored['date_format']
            : $defaults['date_format'];

        $timezone = is_string($stored['timezone'] ?? null) && self::isValidTimezone($stored['timezone'])
            ? $stored['timezone']
            : $defaults['timezone'];

        $firstDay = is_int($stored['first_day_of_week'] ?? null) && in_array($stored['first_day_of_week'], self::WEEK_STARTS, true)
            ? $stored['first_day_of_week']
            : $defaults['first_day_of_week'];

        $showRelative = is_bool($stored['show_relative_time'] ?? null)
            ? $stored['show_relative_time']
            : $defaults['show_relative_time'];

        return [
            'time_format' => $timeFormat,
            'date_format' => $dateFormat,
            'timezone' => $timezone,
            'first_day_of_week' => $firstDay,
            'show_relative_time' => $showRelative,
        ];
    }

    public static function isValidTimezone(string $tz): bool
    {
        return in_array($tz, self::availableTimezones(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function availableTimezones(): array
    {
        return self::$cachedTimezones ??= DateTimeZone::listIdentifiers();
    }
}
