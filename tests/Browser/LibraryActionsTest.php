<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeLibraryActionsSonarr(): void
{
    $series = [
        'id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
        'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 0, 'sizeOnDisk' => 0]]],
        'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 1, 'episodeFileCount' => 0],
    ];

    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => fn (Request $request) => Http::response($request->method() === 'PUT' ? [...$series, 'monitored' => false] : $series),
        'sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 202),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 70, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell', 'airDate' => '2022-02-18', 'hasFile' => false, 'monitored' => true],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p'], ['id' => 6, 'name' => 'Ultra-HD']]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 900], 201),
        'sonarr.local:8989/api/v3/release*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response([], 200)
            : Http::response([
                ['guid' => 'guid-1', 'indexerId' => 3, 'title' => 'Severance.S01.1080p.WEB', 'indexer' => 'NZBgeek', 'protocol' => 'usenet', 'size' => 9_000_000_000, 'ageHours' => 30, 'rejected' => false, 'rejections' => [], 'quality' => ['quality' => ['name' => 'WEBDL-1080p']]],
                ['guid' => 'guid-2', 'indexerId' => 4, 'title' => 'Severance.S01.720p.HDTV', 'indexer' => 'Tracker', 'protocol' => 'torrent', 'seeders' => 5, 'size' => 3_000_000_000, 'ageHours' => 400, 'rejected' => true, 'rejections' => ['Not an upgrade'], 'quality' => ['quality' => ['name' => 'HDTV-720p']]],
            ]),
    ]);
}

beforeEach(function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    fakeLibraryActionsSonarr();
});

test('a member toggles series monitoring and starts a series search', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->click('[data-series-actions] [data-monitor-toggle]')
        ->assertSee('Monitoring updated.')
        ->click('[data-series-actions] [data-search-now]')
        ->assertSee('Search started.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_contains($request->url(), '/api/v3/series/7') && $request['monitored'] === false);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/command') && $request['name'] === 'SeriesSearch');
});

test('a member changes the quality profile', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->click('[data-quality-profile-trigger]')
        ->click('[data-quality-profile-option="6"]')
        ->assertSee('Quality profile updated.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['qualityProfileId'] === 6);
});

test('a member picks a release from the season interactive search and grabs it', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $releaseOneKey = hash('sha256', 'guid-1');
    $releaseTwoKey = hash('sha256', 'guid-2');

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->click('Season 1')
        ->click('[data-season-actions="1"] [data-interactive-search]')
        ->assertSeeIn("[data-release-row=\"{$releaseOneKey}\"]", 'Severance.S01.1080p.WEB')
        ->assertSeeIn("[data-release-row=\"{$releaseTwoKey}\"]", 'Not an upgrade')
        ->assertSeeIn("[data-release-row=\"{$releaseTwoKey}\"] [data-release-grab]", 'Grab anyway')
        ->click("[data-release-row=\"{$releaseOneKey}\"] [data-release-grab]")
        ->assertSee('Release sent to the download client.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v3/release') && $request['guid'] === 'guid-1');
});

test('a member toggles monitoring on a single episode', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->click('Season 1')
        ->click('[data-episode-actions="70"] [data-monitor-toggle]')
        ->assertSee('Monitoring updated.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/episode/monitor') && $request['episodeIds'] === [70]);
});

test('a viewer sees none of the library action controls', function (): void {
    $this->actingAs(User::factory()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->click('Season 1')
        ->assertSee('Good News About Hell')
        ->assertCount('[data-monitor-toggle]', 0)
        ->assertCount('[data-search-now]', 0)
        ->assertCount('[data-interactive-search]', 0)
        ->assertCount('[data-quality-profile-trigger]', 0);
});

test('a member starts a movie search from the movie page', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([
        'radarr.local:7878/api/v3/movie/10' => Http::response(['id' => 10, 'title' => 'Dune', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'images' => []]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/command' => Http::response(['id' => 5], 201),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.show', ['id' => 10], absolute: false))
        ->assertNoSmoke()
        ->click('[data-movie-actions] [data-search-now]')
        ->assertSee('Search started.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/command') && $request['name'] === 'MoviesSearch' && $request['movieIds'] === [10]);
});
