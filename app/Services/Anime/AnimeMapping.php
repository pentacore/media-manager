<?php

declare(strict_types=1);

namespace App\Services\Anime;

use App\Enums\AnimeFormat;

/**
 * The resolved external ids for one seasonal anime entry, or an unmapped
 * marker when neither the dataset nor a confirmed match knows it.
 */
final readonly class AnimeMapping
{
    public function __construct(
        public ?int $tmdbId,
        public string $mediaType,
        public ?int $tvdbId,
        public ?int $tmdbSeason,
    ) {}

    public static function unmapped(AnimeFormat $animeFormat): self
    {
        return new self(null, $animeFormat->seerrMediaType(), null, null);
    }

    public function isMapped(): bool
    {
        return $this->tmdbId !== null;
    }

    /**
     * @return array{tmdbId: int|null, mediaType: string, tvdbId: int|null, tmdbSeason: int|null, mapped: bool}
     */
    public function toArray(): array
    {
        return [
            'tmdbId' => $this->tmdbId,
            'mediaType' => $this->mediaType,
            'tvdbId' => $this->tvdbId,
            'tmdbSeason' => $this->tmdbSeason,
            'mapped' => $this->isMapped(),
        ];
    }
}
