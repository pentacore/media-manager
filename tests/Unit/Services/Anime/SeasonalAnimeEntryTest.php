<?php

declare(strict_types=1);

use App\Enums\AnimeAirStatus;
use App\Enums\AnimeFormat;
use App\Services\Anime\SeasonalAnimeEntry;

test('toArray exposes every field with enums flattened to their values', function (): void {
    $entry = new SeasonalAnimeEntry(
        anilistId: 123,
        malId: 456,
        title: 'Frieren',
        format: AnimeFormat::Tv,
        airStatus: AnimeAirStatus::Finished,
        episodes: 28,
        posterUrl: 'https://img/frieren.jpg',
        startDate: '2023-09-29',
        popularity: 99000,
        score: 9.3,
    );

    expect($entry->toArray())->toBe([
        'anilistId' => 123,
        'malId' => 456,
        'title' => 'Frieren',
        'format' => 'tv',
        'airStatus' => 'finished',
        'episodes' => 28,
        'posterUrl' => 'https://img/frieren.jpg',
        'startDate' => '2023-09-29',
        'popularity' => 99000,
        'score' => 9.3,
    ]);
});

test('toArray preserves null values for optional fields', function (): void {
    $entry = new SeasonalAnimeEntry(
        anilistId: null,
        malId: 789,
        title: 'Unknown',
        format: AnimeFormat::Movie,
        airStatus: AnimeAirStatus::Unknown,
        episodes: null,
        posterUrl: null,
        startDate: null,
        popularity: 0,
        score: null,
    );

    expect($entry->toArray())
        ->toMatchArray([
            'anilistId' => null,
            'malId' => 789,
            'format' => 'movie',
            'airStatus' => 'unknown',
            'episodes' => null,
            'posterUrl' => null,
            'startDate' => null,
            'popularity' => 0,
            'score' => null,
        ]);
});
