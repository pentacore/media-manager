<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A three-month anime broadcast season. Values match the lowercase season
 * slugs used by both AniList (uppercased) and Jikan.
 */
enum AnimeSeason: string
{
    case Winter = 'winter';
    case Spring = 'spring';
    case Summer = 'summer';
    case Fall = 'fall';

    /**
     * The season currently airing for the given month (1-12).
     */
    public static function forMonth(int $month): self
    {
        return match (true) {
            $month >= 1 && $month <= 3 => self::Winter,
            $month >= 4 && $month <= 6 => self::Spring,
            $month >= 7 && $month <= 9 => self::Summer,
            default => self::Fall,
        };
    }

    /**
     * The first calendar month of this season (1-12).
     */
    public function startMonth(): int
    {
        return match ($this) {
            self::Winter => 1,
            self::Spring => 4,
            self::Summer => 7,
            self::Fall => 10,
        };
    }

    /**
     * The AniList `MediaSeason` enum value (uppercase).
     */
    public function anilist(): string
    {
        return strtoupper($this->value);
    }

    /**
     * The season immediately after this one, wrapping the year.
     *
     * @return array{season: self, year: int}
     */
    public function next(int $year): array
    {
        return match ($this) {
            self::Winter => ['season' => self::Spring, 'year' => $year],
            self::Spring => ['season' => self::Summer, 'year' => $year],
            self::Summer => ['season' => self::Fall, 'year' => $year],
            self::Fall => ['season' => self::Winter, 'year' => $year + 1],
        };
    }

    /**
     * The season immediately before this one, wrapping the year.
     *
     * @return array{season: self, year: int}
     */
    public function previous(int $year): array
    {
        return match ($this) {
            self::Winter => ['season' => self::Fall, 'year' => $year - 1],
            self::Spring => ['season' => self::Winter, 'year' => $year],
            self::Summer => ['season' => self::Spring, 'year' => $year],
            self::Fall => ['season' => self::Summer, 'year' => $year],
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
