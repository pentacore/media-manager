<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'test-api-key',
    ]);
});

test('guests are redirected to login from movies index', function (): void {
    $this->get(route('media.movies.index'))->assertRedirect(route('login'));
});

test('viewers cannot access movies index', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('media.movies.index'))->assertForbidden();
});

test('members can list movies', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 1, 'title' => 'Test Movie', 'year' => 2024, 'status' => 'released', 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 2048, 'images' => []],
        ]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => '4K-2160p'],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.movies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Radarr/Movies/Index')
            ->has('movies', 1)
            ->has('qualityProfiles', 1)
            ->where('movies.0.title', 'Test Movie')
        );
});

test('admins can list movies', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([]),
    ]);

    $this->actingAs($admin)->get(route('media.movies.index'))->assertOk();
});

test('movies index redirects when no active radarr connection', function (): void {
    $this->connection->update(['is_active' => false]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.movies.index'))
        ->assertRedirect(route('dashboard'));
});

test('movies index handles connection failure gracefully', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(fn () => Http::response('Service Unavailable', 503));

    $this->actingAs($member)
        ->get(route('media.movies.index'))
        ->assertRedirect(route('dashboard'));
});

test('members can view a single movie', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response([
            'id' => 42, 'title' => 'My Movie', 'year' => 2024, 'overview' => 'A film', 'status' => 'released', 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 5000, 'images' => [], 'runtime' => 120, 'studio' => 'A24', 'rootFolderPath' => '/movies',
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.movies.show', 42))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Radarr/Movies/Show')
            ->where('movie.id', 42)
            ->where('movie.title', 'My Movie')
            ->where('movie.studio', 'A24')
        );
});

test('members can view create form with quality profiles and root folders', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => '4K']]),
        'radarr.local:7878/api/v3/rootfolder' => Http::response([['id' => 1, 'path' => '/movies', 'freeSpace' => 5000]]),
    ]);

    $this->actingAs($member)
        ->get(route('media.movies.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Radarr/Movies/Create')
            ->has('qualityProfiles', 1)
            ->has('rootFolders', 1)
            ->where('searchResults', [])
        );
});

test('create form returns lookup results when q is provided', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([]),
        'radarr.local:7878/api/v3/rootfolder' => Http::response([]),
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 999, 'title' => 'Found Movie', 'year' => 2023, 'overview' => 'Yes'],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.movies.create', ['q' => 'found']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('searchResults', 1)
            ->where('searchResults.0.title', 'Found Movie')
        );
});

test('members can store a new movie', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response(['id' => 99, 'title' => 'New Movie']),
    ]);

    $this->actingAs($member)
        ->post(route('media.movies.store'), [
            'title' => 'New Movie',
            'tmdbId' => 999,
            'qualityProfileId' => 1,
            'rootFolderPath' => '/movies',
            'monitored' => true,
        ])
        ->assertRedirect(route('media.movies.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v3/movie')
        && $request['title'] === 'New Movie'
        && $request['tmdbId'] === 999
    );
});

test('store validates required fields', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.movies.store'), [])
        ->assertSessionHasErrors(['title', 'tmdbId', 'qualityProfileId', 'rootFolderPath']);
});

test('members can delete a movie', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['radarr.local:7878/api/v3/movie/42*' => Http::response(null, 200)]);

    $this->actingAs($member)
        ->delete(route('media.movies.destroy', 42))
        ->assertRedirect(route('media.movies.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), '/api/v3/movie/42')
        && str_contains((string) $request->url(), 'deleteFiles=false')
    );
});

test('delete passes deleteFiles flag when requested', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['radarr.local:7878/api/v3/movie/42*' => Http::response(null, 200)]);

    $this->actingAs($member)
        ->delete(route('media.movies.destroy', 42), ['delete_files' => true])
        ->assertRedirect(route('media.movies.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), 'deleteFiles=true')
    );
});
