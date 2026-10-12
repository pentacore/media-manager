<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function libraryProfileNameSonarr(): void
{
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/7' => Http::response([
            'id' => 7, 'title' => 'Example Show', 'titleSlug' => 'example-show', 'year' => 2024, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 6, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'sizeOnDisk' => 10]]],
            'statistics' => ['sizeOnDisk' => 10, 'episodeCount' => 1, 'episodeFileCount' => 1],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any'], ['id' => 6, 'name' => 'Example Profile']]),
    ]);
}

function libraryProfileNameRadarr(): void
{
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response([
            'id' => 42, 'title' => 'Example Movie', 'titleSlug' => 'example-movie', 'year' => 2021, 'monitored' => true,
            'hasFile' => false, 'qualityProfileId' => 6, 'sizeOnDisk' => 0, 'images' => [],
        ]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any'], ['id' => 6, 'name' => 'Example Profile']]),
    ]);
}

test('a viewer reads the series quality profile by name, without the dropdown', function (): void {
    libraryProfileNameSonarr();
    $this->actingAs(User::factory()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-quality-profile-name]', 'Example Profile')
        ->assertCount('[data-quality-profile-trigger]', 0);
});

test('a viewer reads the movie quality profile by name, not its id', function (): void {
    libraryProfileNameRadarr();
    $this->actingAs(User::factory()->create());

    visit(route('media.movies.show', ['id' => 42], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-quality-profile-name]', 'Example Profile')
        ->assertCount('[data-quality-profile-trigger]', 0);
});

test('a member reads the movie quality profile by name beside the dropdown', function (): void {
    libraryProfileNameRadarr();
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.show', ['id' => 42], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-quality-profile-name]', 'Example Profile')
        ->assertCount('[data-quality-profile-trigger]', 1);
});
