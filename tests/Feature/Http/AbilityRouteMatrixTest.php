<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('mediamanager.search.driver', 'fallback');
    Http::preventStrayRequests();
    Http::fake([
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/*' => Http::response([]),
        'seerr.local:5055/*' => Http::response(['results' => [], 'pageInfo' => ['pages' => 1, 'page' => 1]]),
    ]);

    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    // monitoring.now-playing (an unrelated, pre-existing route in the "viewer
    // read routes" dataset) redirects to the dashboard without an active
    // Emby connection — give it one so that dataset row exercises the
    // ability gate rather than an unrelated missing-connection redirect.
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
});

dataset('viewer read routes', [
    'dashboard' => ['dashboard', []],
    'now playing' => ['monitoring.now-playing', []],
    'watch history' => ['monitoring.watch-history', []],
    'series index' => ['media.series.index', []],
    'series show' => ['media.series.show', ['id' => 1]],
    'movies index' => ['media.movies.index', []],
    'movies show' => ['media.movies.show', ['id' => 1]],
    'search' => ['media.search.index', ['q' => 'dune']],
    'instant search' => ['media.search.instant', ['q' => 'dune']],
]);

dataset('member-only routes', [
    'series create' => ['GET', 'media.series.create', []],
    'series store' => ['POST', 'media.series.store', []],
    'series destroy' => ['DELETE', 'media.series.destroy', ['id' => 1]],
    'movies create' => ['GET', 'media.movies.create', []],
    'movies store' => ['POST', 'media.movies.store', []],
    'movies destroy' => ['DELETE', 'media.movies.destroy', ['id' => 1]],
    'replacement inspect' => ['GET', 'media.replacement.inspect', []],
    'replacement candidates' => ['GET', 'media.replacement.candidates', []],
    'replacement replace' => ['POST', 'media.replacement.replace', []],
    'grab queue' => ['GET', 'media.library.activity.queue', []],
    'anime index' => ['GET', 'media.anime.index', []],
    'anime request' => ['POST', 'media.anime.request', []],
    'requests console' => ['GET', 'media.requests.index', []],
    'requests approve' => ['POST', 'media.requests.approve', ['id' => 1]],
    'requests decline' => ['POST', 'media.requests.decline', ['id' => 1]],
]);

dataset('member write routes', [
    'series store' => ['POST', 'media.series.store', []],
    'movies store' => ['POST', 'media.movies.store', []],
    'series destroy' => ['DELETE', 'media.series.destroy', ['id' => 1]],
    'requests console' => ['GET', 'media.requests.index', []],
    'requests approve' => ['POST', 'media.requests.approve', ['id' => 1]],
]);

test('viewer-level read routes open for viewers', function (string $routeName, array $parameters): void {
    $this->actingAs(User::factory()->create())
        ->get(route($routeName, $parameters))
        ->assertOk();
})->with('viewer read routes');

test('member-level routes are forbidden to viewers', function (string $method, string $routeName, array $parameters): void {
    $this->actingAs(User::factory()->create())
        ->call($method, route($routeName, $parameters))
        ->assertForbidden();
})->with('member-only routes');

test('member-level routes pass the ability gate for members', function (string $method, string $routeName, array $parameters): void {
    $response = $this->actingAs(User::factory()->member()->create())
        ->call($method, route($routeName, $parameters));

    expect($response->getStatusCode())->not->toBe(403);
})->with('member write routes');
