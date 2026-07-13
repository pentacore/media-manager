<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Normalized anime release format across AniList and Jikan. Everything that
 * is not a theatrical movie is treated as episodic TV for request routing.
 */
enum AnimeFormat: string
{
    case Tv = 'tv';
    case Movie = 'movie';
    case Ova = 'ova';
    case Ona = 'ona';
    case Special = 'special';
    case Music = 'music';

    /**
     * Map a raw AniList/Jikan format string onto the canonical enum, falling
     * back to TV for unknown/short formats so it still routes sensibly.
     */
    public static function fromRaw(?string $raw): self
    {
        return match (strtoupper((string) $raw)) {
            'MOVIE' => self::Movie,
            'OVA' => self::Ova,
            'ONA' => self::Ona,
            'SPECIAL' => self::Special,
            'MUSIC' => self::Music,
            default => self::Tv,
        };
    }

    /**
     * Movies route to Radarr; everything else routes to Sonarr as anime.
     */
    public function seerrMediaType(): string
    {
        return $this === self::Movie ? 'movie' : 'tv';
    }
}
