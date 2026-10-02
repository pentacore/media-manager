<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * A 401 is not retried by the arr clients, so these pages answer at once;
 * it renders the "refused — check the connection settings" message.
 */
function arrOutageSonarr(): void
{
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
}

test('the series library shows a Sonarr outage instead of an empty library', function (): void {
    arrOutageSonarr();
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response('Unauthorized', 401),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response('Unauthorized', 401),
    ]);

    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-library-error]', 'Sonarr refused the request — check the connection settings.')
        ->assertDontSee('No series match these filters.')
        ->assertDontSee('0 series');
});

test('a series page says when its episodes and profiles could not load', function (): void {
    arrOutageSonarr();
    Http::fake([
        'sonarr.local:8989/api/v3/series/7' => Http::response([
            'id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'sizeOnDisk' => 10]]],
            'statistics' => ['sizeOnDisk' => 10, 'episodeCount' => 1, 'episodeFileCount' => 1],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response('Unauthorized', 401),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response('Unauthorized', 401),
    ]);

    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->assertSee('Severance')
        ->assertSeeIn('[data-episodes-error]', 'Sonarr refused the request — check the connection settings.')
        ->assertSeeIn('[data-quality-profile-error]', 'Sonarr refused the request — check the connection settings.');
});

test('the add-series search says when the Sonarr lookup failed', function (): void {
    arrOutageSonarr();
    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response('Unauthorized', 401),
    ]);

    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.create', ['q' => 'dune'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-search-error]', 'Sonarr refused the request — check the connection settings.')
        ->assertDontSee('No results found for');
});
