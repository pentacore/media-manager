<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

test('guests are redirected to login from search', function (): void {
    $this->get(route('media.search.index'))->assertRedirect(route('login'));
});

test('viewers cannot access search', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('media.search.index'))->assertForbidden();
});

test('search with empty query returns empty results', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.search.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Search')
            ->where('query', '')
            ->where('results.series', [])
            ->where('results.movies', [])
            ->where('results.requests', [])
            ->where('errors', [])
        );
});

test('search queries all configured services in parallel', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sk']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 1, 'title' => 'Found Series', 'year' => 2024],
        ]),
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 2, 'title' => 'Found Movie', 'year' => 2023],
        ]),
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                ['id' => 3, 'mediaType' => 'tv', 'title' => 'Found Show'],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'found']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query', 'found')
            ->has('results.series', 1)
            ->has('results.movies', 1)
            ->has('results.requests', 1)
            ->where('results.series.0.title', 'Found Series')
            ->where('results.movies.0.title', 'Found Movie')
            ->where('results.requests.0.title', 'Found Show')
            ->where('errors', [])
        );
});

test('search continues when one service is unavailable', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sk']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 1, 'title' => 'OK Series', 'year' => 2024],
        ]),
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response('boom', 500),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('results.series', 1)
            ->where('results.movies', [])
            ->where('errors', ['radarr', 'seerr'])
        );
});

test('search works when no services are configured', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query', 'test')
            ->where('results.series', [])
            ->where('results.movies', [])
            ->where('results.requests', [])
            ->where('errors', ['sonarr', 'radarr', 'seerr'])
        );
});
