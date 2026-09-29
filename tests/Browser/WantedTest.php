<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k', 'name' => 'Sonarr']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k', 'name' => 'Radarr']);
    Http::fake([
        'sonarr.local:8989/api/v3/wanted/missing*' => Http::response(['page' => 1, 'totalRecords' => 1, 'records' => [
            ['id' => 70, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 2, 'title' => 'Half Loop', 'airDateUtc' => '2026-09-10T02:00:00Z', 'series' => ['title' => 'Severance']],
        ]]),
        'sonarr.local:8989/api/v3/series/7' => Http::response(['id' => 7, 'title' => 'Severance', 'year' => 2022]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 1], 201),
        'radarr.local:7878/api/v3/wanted/missing*' => Http::response(['page' => 1, 'totalRecords' => 1, 'records' => [
            ['id' => 10, 'title' => 'Dune', 'year' => 2021, 'digitalRelease' => '2021-10-22T00:00:00Z'],
        ]]),
        'radarr.local:7878/api/v3/command' => Http::response(['id' => 2], 201),
    ]);
});

test("paging one section reloads only that section's list, and Prev/Next disable at the ends", function (): void {
    ServiceConnection::query()->where('type', 'sonarr')->update(['url' => 'http://sonarr-paging.local:8989']);
    Http::fake([
        'sonarr-paging.local:8989/api/v3/wanted/missing*' => Http::response(['page' => 1, 'totalRecords' => 21, 'records' => [
            ['id' => 70, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 2, 'title' => 'Half Loop', 'airDateUtc' => '2026-09-10T02:00:00Z', 'series' => ['title' => 'Severance']],
        ]]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.wanted.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-wanted-row="sonarr-70"]', 'Severance')
        ->assertDisabled('[data-wanted-prev="sonarr"]')
        ->assertEnabled('[data-wanted-next="sonarr"]')
        ->click('[data-wanted-next="sonarr"]')
        ->assertSeeIn('[data-wanted-section="sonarr"]', 'Page 2 of 2')
        ->assertEnabled('[data-wanted-prev="sonarr"]')
        ->assertDisabled('[data-wanted-next="sonarr"]');

    $radarrWantedCalls = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'radarr.local:7878/api/v3/wanted/missing'))
        ->count();
    expect($radarrWantedCalls)->toBe(1);
});

test('the Search-all button hides for a section that failed to load', function (): void {
    ServiceConnection::query()->where('type', 'radarr')->update(['url' => 'http://radarr-down.local:7878']);
    Http::fake(['radarr-down.local:7878/*' => Http::response([], 503)]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.wanted.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-wanted-section="radarr"]', 'Radarr unavailable')
        ->assertMissing('[data-wanted-section="radarr"] [data-wanted-search-all]')
        ->assertPresent('[data-wanted-section="sonarr"] [data-wanted-search-all]');
});

test('a member searches one missing episode and then all missing movies', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.wanted.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-wanted-row="sonarr-70"]', 'Severance')
        ->click('[data-wanted-row="sonarr-70"] [data-search-now]')
        ->assertSee('Search started.')
        ->click('[data-wanted-section="radarr"] [data-wanted-search-all]')
        ->click('[data-wanted-search-all-confirm]')
        ->assertSee('Search started.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'sonarr.local:8989/api/v3/command') && $request['name'] === 'EpisodeSearch' && $request['episodeIds'] === [70]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'radarr.local:7878/api/v3/command') && $request['name'] === 'MissingMoviesSearch');
});
