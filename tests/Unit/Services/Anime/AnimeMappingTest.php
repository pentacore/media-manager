<?php

declare(strict_types=1);

use App\Enums\AnimeFormat;
use App\Services\Anime\AnimeMapping;

test('a mapped entry reports isMapped and serialises all ids', function (): void {
    $mapping = new AnimeMapping(
        tmdbId: 1396,
        mediaType: 'tv',
        tvdbId: 81189,
        tmdbSeason: 2,
    );

    expect($mapping->isMapped())->toBeTrue();
    expect($mapping->toArray())->toBe([
        'tmdbId' => 1396,
        'mediaType' => 'tv',
        'tvdbId' => 81189,
        'tmdbSeason' => 2,
        'mapped' => true,
    ]);
});

test('unmapped builds a null mapping carrying the format media type', function (): void {
    $animeMapping = AnimeMapping::unmapped(AnimeFormat::Tv);
    expect($animeMapping->isMapped())->toBeFalse();
    expect($animeMapping->toArray())->toBe([
        'tmdbId' => null,
        'mediaType' => 'tv',
        'tvdbId' => null,
        'tmdbSeason' => null,
        'mapped' => false,
    ]);

    $movie = AnimeMapping::unmapped(AnimeFormat::Movie);
    expect($movie->toArray()['mediaType'])->toBe('movie');
    expect($movie->isMapped())->toBeFalse();
});
