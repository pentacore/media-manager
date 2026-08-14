<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test-api-key',
    ]);
});

test('guests are redirected to login from series index', function (): void {
    $this->get(route('media.series.index'))->assertRedirect(route('login'));
});

test('viewers cannot access series index', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('media.series.index'))->assertForbidden();
});

test('series index shell renders with connection url before deferred data loads', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.series.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sonarr/Series/Index')
            ->where('connection.url', 'http://sonarr.local:8989')
            ->missing('series')
            ->missing('qualityProfiles')
        );
});

test('members can list series via deferred props', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'title' => 'Test Show', 'titleSlug' => 'test-show', 'year' => 2024, 'status' => 'continuing', 'monitored' => true, 'qualityProfileId' => 1, 'seasons' => [['seasonNumber' => 1]], 'statistics' => ['sizeOnDisk' => 1024, 'episodeCount' => 10, 'episodeFileCount' => 8], 'images' => []],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'HD-1080p'],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.series.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sonarr/Series/Index')
            ->reloadOnly(['series', 'qualityProfiles'], fn ($reload) => $reload
                ->has('series', 1)
                ->has('qualityProfiles', 1)
                ->where('series.0.title', 'Test Show')
                ->where('series.0.title_slug', 'test-show')
                ->where('qualityProfiles.0.name', 'HD-1080p')
            )
        );
});

test('admins can list series', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([]),
    ]);

    $this->actingAs($admin)->get(route('media.series.index'))->assertOk();
});

test('series index redirects when no active sonarr connection', function (): void {
    $this->connection->update(['is_active' => false]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.series.index'))
        ->assertRedirect(route('dashboard'));
});

test('series index deferred props return empty arrays on connection failure', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(fn () => Http::response('Service Unavailable', 503));

    $this->actingAs($member)
        ->get(route('media.series.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->reloadOnly(['series', 'qualityProfiles'], fn ($reload) => $reload
                ->where('series', [])
                ->where('qualityProfiles', [])
            )
        );
});

test('members can view a single series with deferred episodes', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'My Show', 'titleSlug' => 'my-show', 'year' => 2024, 'overview' => 'A show', 'status' => 'ended', 'monitored' => true, 'qualityProfileId' => 1, 'seasons' => [], 'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0], 'images' => [], 'network' => 'HBO', 'runtime' => 60, 'rootFolderPath' => '/tv',
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 1, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot', 'airDate' => '2024-01-01', 'hasFile' => true, 'monitored' => true],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.series.show', 42))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sonarr/Series/Show')
            ->where('connection.url', 'http://sonarr.local:8989')
            ->where('service_connection_id', $this->connection->id)
            ->where('series.id', 42)
            ->where('series.title', 'My Show')
            ->where('series.title_slug', 'my-show')
            ->missing('episodes')
            ->reloadOnly(['episodes'], fn ($reload) => $reload
                ->has('episodes', 1)
                ->where('episodes.0.title', 'Pilot')
            )
        );
});

test('series show redirects when series fetch fails', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['sonarr.local:8989/api/v3/series/42' => Http::response('Service Unavailable', 503)]);

    $this->actingAs($member)
        ->get(route('media.series.show', 42))
        ->assertRedirect(route('dashboard'));
});

test('members can view create form shell with connection url', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.series.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sonarr/Series/Create')
            ->where('connection.url', 'http://sonarr.local:8989')
            ->where('searchTerm', '')
            ->missing('qualityProfiles')
            ->missing('rootFolders')
            ->missing('searchResults')
        );
});

test('create form loads quality profiles and root folders via deferred props', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD']]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([['id' => 1, 'path' => '/tv', 'freeSpace' => 1000]]),
    ]);

    $this->actingAs($member)
        ->get(route('media.series.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sonarr/Series/Create')
            ->reloadOnly(
                ['qualityProfiles', 'rootFolders', 'searchResults'],
                fn ($reload) => $reload
                    ->has('qualityProfiles', 1)
                    ->has('rootFolders', 1)
                    ->where('searchResults', []),
            )
        );
});

test('create form returns lookup results via deferred prop when q is provided', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Found Show', 'year' => 2023, 'overview' => 'Yes', 'remotePoster' => 'http://x/poster.jpg'],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.series.create', ['q' => 'found']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sonarr/Series/Create')
            ->reloadOnly(['searchResults'], fn ($reload) => $reload
                ->has('searchResults', 1)
                ->where('searchResults.0.title', 'Found Show')
            )
        );
});

test('title slug is mapped from sonarr response', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 7, 'title' => 'Slugged', 'titleSlug' => 'slugged-42', 'seasons' => [], 'statistics' => [], 'images' => []],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([]),
    ]);

    $this->actingAs($member)
        ->get(route('media.series.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->reloadOnly(['series'], fn ($reload) => $reload
                ->where('series.0.title_slug', 'slugged-42')
            )
        );
});

test('members can store a new series', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response(['id' => 99, 'title' => 'New Show']),
    ]);

    $this->actingAs($member)
        ->post(route('media.series.store'), [
            'title' => 'New Show',
            'tvdbId' => 12345,
            'qualityProfileId' => 1,
            'rootFolderPath' => '/tv',
            'monitored' => true,
        ])
        ->assertRedirect(route('media.series.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v3/series')
        && $request['title'] === 'New Show'
        && $request['tvdbId'] === 12345
    );
});

test('store validates required fields', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.series.store'), [])
        ->assertSessionHasErrors(['title', 'tvdbId', 'qualityProfileId', 'rootFolderPath']);
});

test('members can delete a series', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['sonarr.local:8989/api/v3/series/42*' => Http::response(null, 200)]);

    $this->actingAs($member)
        ->delete(route('media.series.destroy', 42))
        ->assertRedirect(route('media.series.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), '/api/v3/series/42')
        && str_contains((string) $request->url(), 'deleteFiles=false')
    );
});

test('delete passes deleteFiles flag when requested', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['sonarr.local:8989/api/v3/series/42*' => Http::response(null, 200)]);

    $this->actingAs($member)
        ->delete(route('media.series.destroy', 42), ['delete_files' => true])
        ->assertRedirect(route('media.series.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), 'deleteFiles=true')
    );
});

test('series index prefers external_url for connection link', function (): void {
    $this->connection->update(['external_url' => 'https://sonarr.example.com']);
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.series.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connection.url', 'https://sonarr.example.com')
        );
});
