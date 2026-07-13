<?php

declare(strict_types=1);

use App\Models\AnimeIdMap;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('mediamanager.anime.mapping_url', 'https://fribb.test/anime-list-mini.json');
});

/**
 * A valid Fribb-shaped dataset above the job's MIN_ROWS threshold (1000).
 *
 * @return array<int, array<string, mixed>>
 */
function commandMappingDataset(int $count = 1000): array
{
    return collect()->range(1, $count)->map(fn (int $i): array => [
        'type' => 'TV',
        'anilist_id' => 1_000_000 + $i,
        'mal_id' => 2_000_000 + $i,
        'themoviedb_id' => ['tv' => 3_000_000 + $i],
    ])->all();
}

test('anime:sync-mappings populates the table from the mapping url', function (): void {
    Http::fake(['fribb.test/*' => Http::response(commandMappingDataset())]);

    expect(AnimeIdMap::query()->count())->toBe(0);

    $this->artisan('anime:sync-mappings')->assertSuccessful();

    expect(AnimeIdMap::query()->count())->toBe(1000);
});

test('anime:sync-mappings with --if-empty skips when the table already has rows', function (): void {
    AnimeIdMap::factory()->tv()->create();

    Http::fake(['fribb.test/*' => Http::response(commandMappingDataset())]);

    $this->artisan('anime:sync-mappings', ['--if-empty' => true])
        ->expectsOutputToContain('already populated')
        ->assertSuccessful();

    // Only the seeded row remains; the dataset was never fetched.
    expect(AnimeIdMap::query()->count())->toBe(1);
    Http::assertNothingSent();
});

test('anime:sync-mappings with --if-empty runs when the table is empty', function (): void {
    Http::fake(['fribb.test/*' => Http::response(commandMappingDataset())]);

    $this->artisan('anime:sync-mappings', ['--if-empty' => true])->assertSuccessful();

    expect(AnimeIdMap::query()->count())->toBe(1000);
});
