<?php

declare(strict_types=1);

use App\Jobs\SyncAnimeMappingJob;
use App\Models\AnimeIdMap;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('mediamanager.anime.mapping_url', 'https://fribb.test/anime-list-mini.json');
});

function fakeMappingDataset(array $dataset): void
{
    Http::fake([
        'fribb.test/*' => Http::response($dataset),
    ]);
}

test('handle loads the dataset and inserts one row per valid entry', function (): void {
    fakeMappingDataset([
        [
            'type' => 'TV',
            'anilist_id' => 100,
            'mal_id' => 200,
            'themoviedb_id' => ['tv' => 1396],
            'tvdb_id' => 81189,
            'season' => ['tmdb' => 2, 'tvdb' => 2],
        ],
        [
            'type' => 'MOVIE',
            'anilist_id' => 300,
            'mal_id' => 400,
            'themoviedb_id' => ['movie' => 129],
            'tvdb_id' => null,
            'season' => ['tmdb' => 1],
        ],
    ]);

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->count())->toBe(2);

    $animeIdMap = AnimeIdMap::query()->where('anilist_id', 100)->firstOrFail();
    expect($animeIdMap->tmdb_tv_id)->toBe(1396);
    expect($animeIdMap->tmdb_movie_id)->toBeNull();
    expect($animeIdMap->tvdb_id)->toBe(81189);
    expect($animeIdMap->tmdb_season)->toBe(2);
    expect($animeIdMap->user_confirmed)->toBeFalse();

    $movie = AnimeIdMap::query()->where('anilist_id', 300)->firstOrFail();
    expect($movie->tmdb_movie_id)->toBe(129);
    expect($movie->tmdb_tv_id)->toBeNull();
});

test('handle skips entries with neither an anilist nor a mal id', function (): void {
    fakeMappingDataset([
        ['type' => 'TV', 'themoviedb_id' => ['tv' => 111], 'tvdb_id' => 1],
        ['type' => 'TV', 'anilist_id' => 500, 'themoviedb_id' => ['tv' => 222]],
    ]);

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->count())->toBe(1);
    expect(AnimeIdMap::query()->where('anilist_id', 500)->exists())->toBeTrue();
});

test('handle deletes dataset rows but preserves user_confirmed matches', function (): void {
    // A user-confirmed row that must survive.
    $confirmed = AnimeIdMap::factory()->userConfirmed()->create([
        'anilist_id' => 900,
        'mal_id' => 901,
        'tmdb_tv_id' => 5555,
    ]);

    // A stale dataset row that must be wiped.
    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 111,
        'user_confirmed' => false,
    ]);

    fakeMappingDataset([
        ['type' => 'TV', 'anilist_id' => 100, 'themoviedb_id' => ['tv' => 1396], 'season' => ['tmdb' => 1]],
    ]);

    (new SyncAnimeMappingJob)->handle();

    // Stale dataset row is gone; new dataset row is present; confirmed survives.
    expect(AnimeIdMap::query()->where('anilist_id', 111)->exists())->toBeFalse();
    expect(AnimeIdMap::query()->where('anilist_id', 100)->exists())->toBeTrue();

    $confirmed->refresh();
    expect($confirmed->exists)->toBeTrue();
    expect($confirmed->tmdb_tv_id)->toBe(5555);
    expect($confirmed->user_confirmed)->toBeTrue();
});

test('handle infers media type from a bare themoviedb_id using the entry type', function (): void {
    fakeMappingDataset([
        ['type' => 'MOVIE', 'anilist_id' => 1, 'themoviedb_id' => 129],
        ['type' => 'TV', 'anilist_id' => 2, 'themoviedb_id' => 1396],
    ]);

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->where('anilist_id', 1)->value('tmdb_movie_id'))->toBe(129);
    expect(AnimeIdMap::query()->where('anilist_id', 1)->value('tmdb_tv_id'))->toBeNull();
    expect(AnimeIdMap::query()->where('anilist_id', 2)->value('tmdb_tv_id'))->toBe(1396);
});

test('handle returns early without wiping rows when the payload is not an array', function (): void {
    AnimeIdMap::factory()->tv()->create(['anilist_id' => 111, 'user_confirmed' => false]);

    Http::fake(['fribb.test/*' => Http::response('"not-an-array"')]);

    (new SyncAnimeMappingJob)->handle();

    // Non-array payload → early return, existing rows untouched.
    expect(AnimeIdMap::query()->where('anilist_id', 111)->exists())->toBeTrue();
});
