<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

/**
 * The page object of an Inertia visit.
 *
 * @return array<string, mixed>
 */
function libraryLivePage(string $url, User $user): array
{
    return test()->actingAs($user)
        ->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request())])
        ->assertOk()
        ->json();
}

/**
 * @param  array<string, mixed>  $page
 */
function expectLiveProp(array $page, string $prop, string $channel): void
{
    expect($page['liveProps'][$prop]['listeners'])->toBe([[
        'channel' => ['name' => $channel, 'type' => 'private'],
        'events' => ['WebhookEventProcessed'],
    ]]);
}

test('the series list is live on the Sonarr webhook channel', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);

    $page = libraryLivePage(route('media.series.index', absolute: false), User::factory()->member()->create());

    expectLiveProp($page, 'series', 'webhooks.sonarr');
    expect($page['liveProps'])->not->toHaveKey('qualityProfiles');
});

test('a series page is live on its own connection channel', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response([
        'id' => 7, 'title' => 'Example Show', 'titleSlug' => 'example-show', 'year' => 2024, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'images' => [], 'seasons' => [],
        'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
    ])]);

    $page = libraryLivePage(route('media.series.show', ['id' => 7], absolute: false), User::factory()->member()->create());

    expectLiveProp($page, 'series', 'webhooks.sonarr.'.$sonarr->id);
    expectLiveProp($page, 'episodes', 'webhooks.sonarr.'.$sonarr->id);
});

test('the movie list is live on the Radarr webhook channel', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);

    $page = libraryLivePage(route('media.movies.index', absolute: false), User::factory()->member()->create());

    expectLiveProp($page, 'movies', 'webhooks.radarr');
});

test('a movie page is live on its own connection channel', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake(['radarr.local:7878/api/v3/movie/42' => Http::response([
        'id' => 42, 'title' => 'Example Movie', 'titleSlug' => 'example-movie', 'year' => 2021, 'monitored' => true,
        'hasFile' => false, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => [],
    ])]);

    $page = libraryLivePage(route('media.movies.show', ['id' => 42], absolute: false), User::factory()->member()->create());

    expectLiveProp($page, 'movie', 'webhooks.radarr.'.$radarr->id);
});

test('the Whisparr library is live on the Whisparr webhook channel', function (): void {
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);

    $page = libraryLivePage(route('media.whisparr.index', absolute: false), User::factory()->admin()->create());

    expectLiveProp($page, 'library', 'webhooks.whisparr');
});

test('a Whisparr title and its scenes are live on the connection channel', function (): void {
    $whisparr = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    Http::fake(['whisparr.local:6969/api/v3/series/5' => Http::response(['id' => 5, 'title' => 'Site Five'])]);

    $page = libraryLivePage(route('media.whisparr.show', ['id' => 5], absolute: false), User::factory()->admin()->create());

    expectLiveProp($page, 'item', 'webhooks.whisparr.'.$whisparr->id);
    expectLiveProp($page, 'scenes', 'webhooks.whisparr.'.$whisparr->id);
});

test('the Seerr requests and summary are live on the Seerr webhook channel', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    $page = libraryLivePage(route('media.requests.index', absolute: false), User::factory()->member()->create());

    expectLiveProp($page, 'requests', 'webhooks.seerr');
    expectLiveProp($page, 'summary', 'webhooks.seerr');
});
