<?php

declare(strict_types=1);

use App\Enums\AnimeAirStatus;
use App\Enums\AnimeFormat;
use App\Models\AnimeIdMap;
use App\Services\Anime\AnimeIdMapper;
use App\Services\Anime\SeasonalAnimeEntry;

beforeEach(function (): void {
    $this->mapper = new AnimeIdMapper;
});

function anilistEntry(int $anilistId, ?int $malId = null, AnimeFormat $animeFormat = AnimeFormat::Tv): SeasonalAnimeEntry
{
    return new SeasonalAnimeEntry(
        anilistId: $anilistId,
        malId: $malId,
        title: 'Test',
        format: $animeFormat,
        airStatus: AnimeAirStatus::Finished,
        episodes: 12,
        posterUrl: null,
        startDate: null,
        popularity: 0,
        score: null,
    );
}

test('entryKey prefers the anilist id and falls back to a namespaced mal id', function (): void {
    expect($this->mapper->entryKey(anilistEntry(123, 456)))->toBe('anilist:123');

    $malOnly = new SeasonalAnimeEntry(null, 789, 'T', AnimeFormat::Tv, AnimeAirStatus::Finished, 1, null, null, 0, null);
    expect($this->mapper->entryKey($malOnly))->toBe('mal:789');
});

test('resolveMany maps a tv entry to its tmdb_tv_id and tvdb data', function (): void {
    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 100,
        'mal_id' => 200,
        'tmdb_tv_id' => 1396,
        'tvdb_id' => 81189,
        'tmdb_season' => 2,
    ]);

    $resolved = $this->mapper->resolveMany(collect([anilistEntry(100, 200, AnimeFormat::Tv)]));

    $mapping = $resolved['anilist:100'];
    expect($mapping->isMapped())->toBeTrue();
    expect($mapping->toArray())->toBe([
        'tmdbId' => 1396,
        'mediaType' => 'tv',
        'tvdbId' => 81189,
        'tmdbSeason' => 2,
        'mapped' => true,
    ]);
});

test('resolveMany maps a movie entry to its tmdb_movie_id', function (): void {
    AnimeIdMap::factory()->movie()->create([
        'anilist_id' => 300,
        'mal_id' => 400,
        'tmdb_movie_id' => 129,
    ]);

    $resolved = $this->mapper->resolveMany(collect([anilistEntry(300, 400, AnimeFormat::Movie)]));

    $mapping = $resolved['anilist:300'];
    expect($mapping->isMapped())->toBeTrue();
    expect($mapping->toArray())->toMatchArray([
        'tmdbId' => 129,
        'mediaType' => 'movie',
        'mapped' => true,
    ]);
});

test('resolveMany returns an unmapped mapping when no row exists', function (): void {
    $resolved = $this->mapper->resolveMany(collect([anilistEntry(999, 888)]));

    $mapping = $resolved['anilist:999'];
    expect($mapping->isMapped())->toBeFalse();
    expect($mapping->toArray()['mediaType'])->toBe('tv');
});

test('resolveMany falls back to the mal id when the anilist id has no row', function (): void {
    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => null,
        'mal_id' => 555,
        'tmdb_tv_id' => 42,
    ]);

    // Entry carries an anilist id that is unknown, but the mal id resolves.
    $resolved = $this->mapper->resolveMany(collect([anilistEntry(1234567, 555, AnimeFormat::Tv)]));

    expect($resolved['anilist:1234567']->tmdbId)->toBe(42);
});

test('resolveMany prefers a user_confirmed row over a dataset row for the same id', function (): void {
    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 700,
        'mal_id' => null,
        'tmdb_tv_id' => 111,
        'user_confirmed' => false,
    ]);
    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 700,
        'mal_id' => null,
        'tmdb_tv_id' => 222,
        'user_confirmed' => true,
    ]);

    $resolved = $this->mapper->resolveMany(collect([anilistEntry(700, null, AnimeFormat::Tv)]));

    expect($resolved['anilist:700']->tmdbId)->toBe(222);
});

test('persistConfirmedMatch upserts a user_confirmed tv row', function (): void {
    $row = $this->mapper->persistConfirmedMatch(500, 600, 1396, AnimeFormat::Tv);

    expect($row->user_confirmed)->toBeTrue();
    expect($row->tmdb_tv_id)->toBe(1396);
    expect($row->tmdb_movie_id)->toBeNull();
    expect($row->type)->toBe('TV');

    $this->assertDatabaseHas('anime_id_maps', [
        'anilist_id' => 500,
        'mal_id' => 600,
        'tmdb_tv_id' => 1396,
        'user_confirmed' => true,
    ]);
});

test('persistConfirmedMatch stores a movie tmdb id under tmdb_movie_id', function (): void {
    $row = $this->mapper->persistConfirmedMatch(null, 601, 129, AnimeFormat::Movie);

    expect($row->tmdb_movie_id)->toBe(129);
    expect($row->tmdb_tv_id)->toBeNull();
    expect($row->type)->toBe('MOVIE');
    expect($row->user_confirmed)->toBeTrue();
});

test('persistConfirmedMatch overwrites an existing confirmed row for the same anilist id', function (): void {
    $this->mapper->persistConfirmedMatch(800, null, 10, AnimeFormat::Tv);
    $this->mapper->persistConfirmedMatch(800, null, 20, AnimeFormat::Tv);

    expect(AnimeIdMap::query()->where('anilist_id', 800)->where('user_confirmed', true)->count())->toBe(1);
    expect(AnimeIdMap::query()->where('anilist_id', 800)->value('tmdb_tv_id'))->toBe(20);
});
