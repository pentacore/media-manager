<?php

declare(strict_types=1);

use App\Enums\AnimeAirStatus;

test('fromRaw maps AniList and Jikan status strings onto the enum', function (?string $raw, AnimeAirStatus $animeAirStatus): void {
    expect(AnimeAirStatus::fromRaw($raw))->toBe($animeAirStatus);
})->with([
    'anilist releasing' => ['RELEASING', AnimeAirStatus::Airing],
    'jikan currently airing' => ['Currently Airing', AnimeAirStatus::Airing],
    'anilist not yet released' => ['NOT_YET_RELEASED', AnimeAirStatus::Upcoming],
    'jikan not yet aired' => ['Not yet aired', AnimeAirStatus::Upcoming],
    'anilist finished' => ['FINISHED', AnimeAirStatus::Finished],
    'jikan finished airing' => ['Finished Airing', AnimeAirStatus::Finished],
]);

test('fromRaw falls back to Unknown for unrecognised or null status', function (?string $raw): void {
    expect(AnimeAirStatus::fromRaw($raw))->toBe(AnimeAirStatus::Unknown);
})->with([
    'hiatus' => 'HIATUS',
    'empty' => '',
    'null' => null,
]);
