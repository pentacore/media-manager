<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Normalized airing status across AniList and Jikan.
 */
enum AnimeAirStatus: string
{
    case Airing = 'airing';
    case Upcoming = 'upcoming';
    case Finished = 'finished';
    case Unknown = 'unknown';

    /**
     * Map a raw AniList (`RELEASING`, `NOT_YET_RELEASED`, `FINISHED`) or Jikan
     * (`Currently Airing`, `Not yet aired`, `Finished Airing`) status string.
     */
    public static function fromRaw(?string $raw): self
    {
        return match (strtoupper((string) $raw)) {
            'RELEASING', 'CURRENTLY AIRING' => self::Airing,
            'NOT_YET_RELEASED', 'NOT YET AIRED' => self::Upcoming,
            'FINISHED', 'FINISHED AIRING' => self::Finished,
            default => self::Unknown,
        };
    }
}
