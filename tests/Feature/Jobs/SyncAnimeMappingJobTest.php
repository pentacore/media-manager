<?php

declare(strict_types=1);

use App\Jobs\SyncAnimeMappingJob;
use App\Models\AnimeIdMap;
use Illuminate\Support\Facades\Cache;
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

/**
 * Build a valid Fribb-shaped dataset with at least MIN_ROWS (1000) mappable
 * rows so the job clears the delete+replace threshold. Any extra rows passed
 * in are prepended so a test can assert their specific shape.
 *
 * @param  array<int, array<string, mixed>>  $extra
 * @return array<int, array<string, mixed>>
 */
function fribbDatasetWithMinRows(array $extra = [], int $count = 1000): array
{
    $filler = collect()->range(1, $count)->map(fn (int $i): array => [
        'type' => 'TV',
        // Offset well past any ids the caller supplies to keep them distinct.
        'anilist_id' => 1_000_000 + $i,
        'mal_id' => 2_000_000 + $i,
        'themoviedb_id' => ['tv' => 3_000_000 + $i],
    ])->all();

    return [...$extra, ...$filler];
}

test('handle loads the dataset and inserts one row per valid entry', function (): void {
    fakeMappingDataset(fribbDatasetWithMinRows([
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
            // Fribb real shape: movie themoviedb ids are arrays.
            'themoviedb_id' => ['movie' => [128]],
            'tvdb_id' => null,
            'season' => ['tmdb' => 1],
        ],
    ]));

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->count())->toBe(1002);

    $animeIdMap = AnimeIdMap::query()->where('anilist_id', 100)->firstOrFail();
    expect($animeIdMap->tmdb_tv_id)->toBe(1396);
    expect($animeIdMap->tmdb_movie_id)->toBeNull();
    expect($animeIdMap->tvdb_id)->toBe(81189);
    expect($animeIdMap->tmdb_season)->toBe(2);
    expect($animeIdMap->user_confirmed)->toBeFalse();

    $movie = AnimeIdMap::query()->where('anilist_id', 300)->firstOrFail();
    // The array id must extract its first element (128), not collapse to 1.
    expect($movie->tmdb_movie_id)->toBe(128);
    expect($movie->tmdb_tv_id)->toBeNull();
});

test('handle extracts the first element of an array movie id and keeps scalar tv ids', function (): void {
    fakeMappingDataset(fribbDatasetWithMinRows([
        ['type' => 'MOVIE', 'anilist_id' => 10, 'themoviedb_id' => ['movie' => [128, 999]]],
        ['type' => 'TV', 'anilist_id' => 11, 'themoviedb_id' => ['tv' => 26209]],
    ]));

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->where('anilist_id', 10)->value('tmdb_movie_id'))->toBe(128);
    expect(AnimeIdMap::query()->where('anilist_id', 10)->value('tmdb_tv_id'))->toBeNull();
    expect(AnimeIdMap::query()->where('anilist_id', 11)->value('tmdb_tv_id'))->toBe(26209);
    expect(AnimeIdMap::query()->where('anilist_id', 11)->value('tmdb_movie_id'))->toBeNull();
});

test('handle skips entries with neither an anilist nor a mal id', function (): void {
    fakeMappingDataset(fribbDatasetWithMinRows([
        ['type' => 'TV', 'themoviedb_id' => ['tv' => 111], 'tvdb_id' => 1],
        ['type' => 'TV', 'anilist_id' => 500, 'themoviedb_id' => ['tv' => 222]],
    ]));

    (new SyncAnimeMappingJob)->handle();

    // The keyless entry is skipped; only the 500 row + 1000 filler survive.
    expect(AnimeIdMap::query()->count())->toBe(1001);
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

    fakeMappingDataset(fribbDatasetWithMinRows([
        ['type' => 'TV', 'anilist_id' => 100, 'themoviedb_id' => ['tv' => 1396], 'season' => ['tmdb' => 1]],
    ]));

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
    fakeMappingDataset(fribbDatasetWithMinRows([
        ['type' => 'MOVIE', 'anilist_id' => 1, 'themoviedb_id' => 129],
        ['type' => 'TV', 'anilist_id' => 2, 'themoviedb_id' => 1396],
    ]));

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->where('anilist_id', 1)->value('tmdb_movie_id'))->toBe(129);
    expect(AnimeIdMap::query()->where('anilist_id', 1)->value('tmdb_tv_id'))->toBeNull();
    expect(AnimeIdMap::query()->where('anilist_id', 2)->value('tmdb_tv_id'))->toBe(1396);
});

test('handle records the last-synced marker after a successful reload', function (): void {
    Cache::forget('anime:mapping:last-synced-at');

    fakeMappingDataset(fribbDatasetWithMinRows());

    (new SyncAnimeMappingJob)->handle();

    expect(Cache::get('anime:mapping:last-synced-at'))->not->toBeNull();
});

test('handle returns early without wiping rows when the payload is not an array', function (): void {
    AnimeIdMap::factory()->tv()->create(['anilist_id' => 111, 'user_confirmed' => false]);

    Http::fake(['fribb.test/*' => Http::response('"not-an-array"')]);

    (new SyncAnimeMappingJob)->handle();

    // Non-array payload → early return, existing rows untouched.
    expect(AnimeIdMap::query()->where('anilist_id', 111)->exists())->toBeTrue();
});

test('handle keeps existing rows when the payload is an empty list', function (): void {
    AnimeIdMap::factory()->tv()->create(['anilist_id' => 111, 'user_confirmed' => false]);

    fakeMappingDataset([]);

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->where('anilist_id', 111)->exists())->toBeTrue();
});

test('handle keeps existing rows when the payload is an assoc/error object', function (): void {
    AnimeIdMap::factory()->tv()->create(['anilist_id' => 111, 'user_confirmed' => false]);

    // An error object like {"error":"rate limited"} passes is_array() but is
    // not a list, so it must be rejected before the delete.
    Http::fake(['fribb.test/*' => Http::response(['error' => 'rate limited'])]);

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->where('anilist_id', 111)->exists())->toBeTrue();
});

test('handle keeps existing rows when a valid list has fewer than the minimum mappable rows', function (): void {
    AnimeIdMap::factory()->tv()->create(['anilist_id' => 111, 'user_confirmed' => false]);

    // A well-formed but too-small list (below MIN_ROWS = 1000) is treated as
    // corrupt: existing rows must be kept and the small list ignored.
    fakeMappingDataset(fribbDatasetWithMinRows([
        ['type' => 'TV', 'anilist_id' => 222, 'themoviedb_id' => ['tv' => 1396]],
    ], count: 10));

    (new SyncAnimeMappingJob)->handle();

    expect(AnimeIdMap::query()->where('anilist_id', 111)->exists())->toBeTrue();
    expect(AnimeIdMap::query()->where('anilist_id', 222)->exists())->toBeFalse();
});
