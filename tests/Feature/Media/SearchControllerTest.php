<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

test('search with empty query returns empty results immediately', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.search.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Search')
            ->where('query', '')
            ->where('seriesResults.results', [])
            ->where('seriesResults.error', null)
            ->where('movieResults.results', [])
            ->where('movieResults.error', null)
            ->where('requestResults.results', [])
            ->where('requestResults.error', null)
        );
});

test('search exposes connection urls as a non-deferred prop', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sk']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.search.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Search')
            ->where('connections.sonarr.url', 'http://sonarr.local:8989')
            ->where('connections.radarr.url', 'http://radarr.local:7878')
            ->where('connections.seerr.url', 'http://seerr.local:5055')
        );
});

test('search reports null connection entries when services are not configured', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.search.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.sonarr', null)
            ->where('connections.radarr', null)
            ->where('connections.seerr', null)
        );
});

test('search defers per-service props on initial render', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sk']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'anything']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Search')
            ->where('query', 'anything')
            ->missing('seriesResults')
            ->missing('movieResults')
            ->missing('requestResults')
        );
});

test('search resolves deferred series results from Sonarr library', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            [
                'id' => 10,
                'tvdbId' => 1,
                'title' => 'Found Series',
                'year' => 2024,
                'titleSlug' => 'found-series',
                'status' => 'continuing',
                'monitored' => true,
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'found']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('seriesResults.error', null)
                ->has('seriesResults.results', 1)
                ->where('seriesResults.results.0.id', 10)
                ->where('seriesResults.results.0.title', 'Found Series')
                ->where('seriesResults.results.0.tvdb_id', 1)
                ->where('seriesResults.results.0.title_slug', 'found-series')
                ->where('seriesResults.results.0.monitored', true)
            )
        );
});

test('search resolves deferred movie results from Radarr library', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            [
                'id' => 22,
                'tmdbId' => 2,
                'title' => 'Found Movie',
                'year' => 2023,
                'titleSlug' => 'found-movie',
                'status' => 'released',
                'monitored' => true,
                'hasFile' => true,
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'found']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('movieResults.error', null)
                ->has('movieResults.results', 1)
                ->where('movieResults.results.0.id', 22)
                ->where('movieResults.results.0.title', 'Found Movie')
                ->where('movieResults.results.0.tmdb_id', 2)
                ->where('movieResults.results.0.title_slug', 'found-movie')
                ->where('movieResults.results.0.monitored', true)
                ->where('movieResults.results.0.has_file', true)
            )
        );
});

test('search resolves deferred request results from Seerr search + detail endpoints', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'page' => 1,
            'totalPages' => 1,
            'totalResults' => 1,
            'results' => [
                [
                    'id' => 1396,
                    'mediaType' => 'tv',
                    'name' => 'Found Show',
                    'overview' => 'Pilot.',
                    'posterPath' => '/poster.jpg',
                    // Presence flag only — actual requests come from /tv/{id}.
                    'mediaInfo' => ['id' => 9, 'mediaType' => 'tv', 'tmdbId' => 1396, 'status' => 5],
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/tv/1396' => Http::response([
            'id' => 1396,
            'name' => 'Found Show',
            'overview' => 'Pilot.',
            'posterPath' => '/poster.jpg',
            'mediaInfo' => [
                'id' => 9,
                'mediaType' => 'tv',
                'tmdbId' => 1396,
                'tvdbId' => 81189,
                'status' => 5,
                'requests' => [
                    ['id' => 3, 'status' => 2],
                ],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'found']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('requestResults.error', null)
                ->has('requestResults.results', 1)
                ->where('requestResults.results.0.id', 3)
                ->where('requestResults.results.0.title', 'Found Show')
                ->where('requestResults.results.0.media_type', 'tv')
                ->where('requestResults.results.0.tmdb_id', 1396)
                ->where('requestResults.results.0.tvdb_id', 81189)
                ->where('requestResults.results.0.status', 2)
                ->where('requestResults.results.0.overview', 'Pilot.')
                ->where('requestResults.results.0.poster_path', '/poster.jpg')
            )
        );
});

test('search ignores Seerr hits that are not yet tracked in the local DB', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                ['id' => 999, 'mediaType' => 'movie', 'title' => 'Not Yet Requested', 'mediaInfo' => null],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'anything']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('requestResults.error', null)
                ->where('requestResults.results', [])
            )
        );

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v1/movie/'));
});

test('search drops hits whose detail lookup returns no requests', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                [
                    'id' => 1000,
                    'mediaType' => 'movie',
                    'title' => 'Tracked Without Requests',
                    'mediaInfo' => ['id' => 1, 'mediaType' => 'movie', 'tmdbId' => 1000],
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/movie/1000' => Http::response([
            'id' => 1000,
            'title' => 'Tracked Without Requests',
            'mediaInfo' => ['id' => 1, 'mediaType' => 'movie', 'tmdbId' => 1000, 'requests' => []],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'tracked']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('requestResults.results', [])
            )
        );
});

test('search filters out person hits returned by Seerr multi-search', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                ['id' => 500, 'mediaType' => 'person', 'name' => 'Some Actor', 'mediaInfo' => null],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'actor']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('requestResults.results', [])
            )
        );
});

test('search surfaces approved or available Seerr requests regardless of status', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'jk']);

    $member = User::factory()->member()->create();

    // Regression for #38: requests that had already been approved/available
    // (status 2 / 5) used to disappear because the controller pulled a
    // fixed window of /request rows. Now we walk /search → details and
    // include every request the detail endpoint exposes.
    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                [
                    'id' => 7777,
                    'mediaType' => 'tv',
                    'name' => 'FBI',
                    'mediaInfo' => ['id' => 50, 'mediaType' => 'tv', 'tmdbId' => 7777, 'status' => 5],
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/tv/7777' => Http::response([
            'id' => 7777,
            'name' => 'FBI',
            'mediaInfo' => [
                'id' => 50,
                'mediaType' => 'tv',
                'tmdbId' => 7777,
                'tvdbId' => 333333,
                'status' => 5,
                'requests' => [
                    ['id' => 42, 'status' => 2],
                    ['id' => 43, 'status' => 5],
                ],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'FBI']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('requestResults.results', 2)
                ->where('requestResults.results.0.id', 42)
                ->where('requestResults.results.0.status', 2)
                ->where('requestResults.results.1.id', 43)
                ->where('requestResults.results.1.status', 5)
            )
        );

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v1/request'));
});

test('sonarr library results are filtered by case-insensitive title substring', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'tvdbId' => 101, 'title' => 'Breaking Bad', 'year' => 2008, 'titleSlug' => 'breaking-bad'],
            ['id' => 2, 'tvdbId' => 102, 'title' => 'Better Call Saul', 'year' => 2015, 'titleSlug' => 'better-call-saul'],
            ['id' => 3, 'tvdbId' => 103, 'title' => 'The Wire', 'year' => 2002, 'titleSlug' => 'the-wire'],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'breaking']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('seriesResults.results', 1)
                ->where('seriesResults.results.0.title', 'Breaking Bad')
            )
        );
});

test('radarr library results are filtered by case-insensitive title substring', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 1, 'tmdbId' => 201, 'title' => 'Inception', 'year' => 2010, 'titleSlug' => 'inception'],
            ['id' => 2, 'tmdbId' => 202, 'title' => 'Interstellar', 'year' => 2014, 'titleSlug' => 'interstellar'],
            ['id' => 3, 'tmdbId' => 203, 'title' => 'Tenet', 'year' => 2020, 'titleSlug' => 'tenet'],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'INTER']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('movieResults.results', 1)
                ->where('movieResults.results.0.title', 'Interstellar')
            )
        );
});

test('search surfaces per-service error message when a service fails', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sk']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'tvdbId' => 1, 'title' => 'Test Series', 'year' => 2024, 'titleSlug' => 'test-series'],
        ]),
        'radarr.local:7878/api/v3/movie' => Http::response('boom', 500),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('seriesResults.results', 1)
                ->where('seriesResults.error', null)
                ->where('movieResults.results', [])
                ->where('movieResults.error', fn ($value): bool => is_string($value) && $value !== '')
                ->where('requestResults.results', [])
                ->where('requestResults.error', 'No active Seerr connection configured.')
            )
        );
});

test('search does not leak provider exception details to members', function (): void {
    Log::spy();
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'rk']);

    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response('LEAKED-PROVIDER-SECRET', 500),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('movieResults.results', [])
                ->where('movieResults.error', 'Radarr search is temporarily unavailable.')
            )
        );

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Media search failed.'
            && ($context['service'] ?? null) === 'radarr'
            && str_contains((string) ($context['message'] ?? ''), 'LEAKED-PROVIDER-SECRET')
    );
});

test('search reports no-connection errors when services are not configured', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query', 'test')
            ->loadDeferredProps(fn ($page) => $page
                ->where('seriesResults.results', [])
                ->where('seriesResults.error', 'No active Sonarr connection configured.')
                ->where('movieResults.results', [])
                ->where('movieResults.error', 'No active Radarr connection configured.')
                ->where('requestResults.results', [])
                ->where('requestResults.error', 'No active Seerr connection configured.')
            )
        );
});

test('default scope skips Prowlarr fan-out entirely', function (): void {
    $member = User::factory()->member()->create();
    ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
    ]);

    Http::fake();

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'severance']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('indexerResults.results', [])
            ->where('indexerResults.error', null)
        );

    // Sonarr/Radarr/Seerr requests can still go out via deferred props,
    // but Prowlarr must not have been touched.
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'prowlarr'));
});

test('scope=indexers fans out to Prowlarr and returns release rows', function (): void {
    $member = User::factory()->member()->create();
    ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([
            [
                'guid' => 'guid-1',
                'title' => 'Severance.S02E07.1080p.WEB-DL.x264',
                'indexer' => 'ETTV',
                'categories' => [['name' => 'TV/HD']],
                'size' => 2_500_000_000,
                'seeders' => 412,
                'leechers' => 18,
                'publishDate' => now()->subMinutes(12)->toIso8601String(),
                'downloadUrl' => 'http://example/dl/1.torrent',
                'infoUrl' => 'http://example/info/1',
                'qualityWeight' => 96,
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.search.index', ['q' => 'severance', 'scope' => 'indexers']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('indexerResults.results', 1)
                ->where('indexerResults.results.0.title', 'Severance.S02E07.1080p.WEB-DL.x264')
                ->where('indexerResults.results.0.tracker', 'ETTV')
                ->where('indexerResults.results.0.size_bytes', 2_500_000_000)
                ->where('indexerResults.results.0.seeders', 412)
            )
        );
});
