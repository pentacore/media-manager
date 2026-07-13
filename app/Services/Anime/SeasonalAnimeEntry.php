<?php

declare(strict_types=1);

namespace App\Services\Anime;

use App\Enums\AnimeAirStatus;
use App\Enums\AnimeFormat;

/**
 * Source-agnostic representation of a single anime in a broadcast season.
 * Both AniListClient and JikanClient normalize their payloads into this.
 */
final readonly class SeasonalAnimeEntry
{
    public function __construct(
        public ?int $anilistId,
        public ?int $malId,
        public string $title,
        public AnimeFormat $format,
        public AnimeAirStatus $airStatus,
        public ?int $episodes,
        public ?string $posterUrl,
        public ?string $startDate,
        public int $popularity,
        public ?float $score,
    ) {}

    /**
     * @return array{
     *     anilistId: int|null,
     *     malId: int|null,
     *     title: string,
     *     format: string,
     *     airStatus: string,
     *     episodes: int|null,
     *     posterUrl: string|null,
     *     startDate: string|null,
     *     popularity: int,
     *     score: float|null
     * }
     */
    public function toArray(): array
    {
        return [
            'anilistId' => $this->anilistId,
            'malId' => $this->malId,
            'title' => $this->title,
            'format' => $this->format->value,
            'airStatus' => $this->airStatus->value,
            'episodes' => $this->episodes,
            'posterUrl' => $this->posterUrl,
            'startDate' => $this->startDate,
            'popularity' => $this->popularity,
            'score' => $this->score,
        ];
    }
}
