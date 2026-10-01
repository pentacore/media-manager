<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
});

function whisparrPageConnection(WhisparrVersion $whisparrVersion = WhisparrVersion::V3): ServiceConnection
{
    return ServiceConnection::factory()->whisparr()->whisparrVersion($whisparrVersion)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'whisparr-secret-key', 'name' => 'Whisparr',
    ]);
}

test('the index renders an empty state without an active Whisparr connection', function (): void {
    $this->actingAs($this->admin)
        ->get(route('media.whisparr.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Whisparr/Index')
            ->where('connection', null)
            ->missing('library'));
});

test('the index lists v3 movies through the presenter, with profiles in their own group', function (): void {
    $serviceConnection = whisparrPageConnection();
    Http::fake([
        'whisparr.local:6969/api/v3/movie' => Http::response([
            ['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => []],
        ]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
    ]);

    $this->actingAs($this->admin)
        ->get(route('media.whisparr.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Whisparr/Index')
            ->where('connection.id', $serviceConnection->id)
            ->where('connection.version', 'v3')
            ->missing('library')
            ->loadDeferredProps('default', fn ($reload) => $reload
                ->where('library.error', null)
                ->where('library.items.0', [
                    'id' => 11, 'kind' => 'movie', 'title' => 'Aurora Scene', 'year' => 2024, 'monitored' => true,
                    'has_file' => false, 'size_bytes' => 0, 'poster_url' => null, 'quality_profile_id' => 1,
                ]))
            ->loadDeferredProps('qualityProfiles', fn ($reload) => $reload
                ->where('qualityProfiles', [['id' => 1, 'name' => 'Any']])));
});

test('the index reads v2 sites from the series resource', function (): void {
    whisparrPageConnection(WhisparrVersion::V2);
    Http::fake([
        'whisparr.local:6969/api/v3/series' => Http::response([
            ['id' => 5, 'title' => 'Site Five', 'monitored' => true, 'statistics' => ['episodeFileCount' => 2, 'sizeOnDisk' => 500]],
        ]),
    ]);

    $this->actingAs($this->admin)
        ->get(route('media.whisparr.index'))
        ->assertInertia(fn ($page) => $page
            ->where('connection.version', 'v2')
            ->loadDeferredProps('default', fn ($reload) => $reload
                ->where('library.items.0.kind', 'site')
                ->where('library.items.0.has_file', true)
                ->where('library.items.0.size_bytes', 500)));
});

test('a Whisparr outage renders as an error, never as an empty library', function (int $status, string $message): void {
    Sleep::fake();
    whisparrPageConnection();
    Http::fake(['whisparr.local:6969/api/v3/movie' => Http::response(['message' => 'upstream body /data/secret'], $status)]);

    $this->actingAs($this->admin)
        ->get(route('media.whisparr.index'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', fn ($reload) => $reload
                ->where('library.items', [])
                ->where('library.error', $message)));
})->with([
    'server error' => [503, 'Whisparr is unreachable right now.'],
    'refusal' => [401, 'Whisparr refused the request — check the connection settings.'],
]);

test('a dropped connection to Whisparr reads as an outage', function (): void {
    Sleep::fake();
    whisparrPageConnection();
    Http::fake(['whisparr.local:6969/api/v3/movie' => fn () => throw new ConnectionException('Connection refused')]);

    $this->actingAs($this->admin)
        ->get(route('media.whisparr.index'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', fn ($reload) => $reload->where('library.error', 'Whisparr is unreachable right now.')));
});

test('the show page renders a v3 movie with no scenes and deferred profiles', function (): void {
    whisparrPageConnection();
    Http::fake([
        'whisparr.local:6969/api/v3/movie/11' => Http::response([
            'id' => 11, 'title' => 'Aurora Scene', 'year' => 2024, 'monitored' => true, 'hasFile' => true, 'sizeOnDisk' => 10,
            'qualityProfileId' => 1, 'path' => '/data/whisparr/Aurora Scene', 'overview' => 'First.',
        ]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
    ]);

    $this->actingAs($this->admin)
        ->get(route('media.whisparr.show', ['id' => 11]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Whisparr/Show')
            ->where('item.title', 'Aurora Scene')
            ->where('item.path', '/data/whisparr/Aurora Scene')
            ->where('item.overview', 'First.')
            ->where('scenes', ['groups' => [], 'error' => null])
            ->missing('qualityProfiles')
            ->loadDeferredProps('qualityProfiles', fn ($reload) => $reload->where('qualityProfiles.0.name', 'Any')));
});

test('a v2 site loads its scenes grouped by year in the scenes group', function (): void {
    whisparrPageConnection(WhisparrVersion::V2);
    Http::fake([
        'whisparr.local:6969/api/v3/series/5' => Http::response(['id' => 5, 'title' => 'Site Five']),
        'whisparr.local:6969/api/v3/episode*' => Http::response([
            ['id' => 51, 'seasonNumber' => 2024, 'title' => 'Scene A', 'airDate' => '2024-02-01', 'hasFile' => true, 'monitored' => true],
        ]),
    ]);

    $this->actingAs($this->admin)
        ->get(route('media.whisparr.show', ['id' => 5]))
        ->assertInertia(fn ($page) => $page
            ->where('item.kind', 'site')
            ->missing('scenes')
            ->loadDeferredProps('scenes', fn ($reload) => $reload
                ->where('scenes.error', null)
                ->where('scenes.groups.0.year', 2024)
                ->where('scenes.groups.0.scenes.0.title', 'Scene A')));
});

test('a title that is gone from Whisparr sends the admin back to the library', function (): void {
    whisparrPageConnection();
    Http::fake(['whisparr.local:6969/api/v3/movie/99' => Http::response(['message' => 'NotFound'], 404)]);

    $this->actingAs($this->admin)
        ->get(route('media.whisparr.show', ['id' => 99]))
        ->assertRedirect(route('media.whisparr.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'That title is no longer in Whisparr.');
});

test('the show page without a connection sends the admin back to the library', function (): void {
    $this->actingAs($this->admin)
        ->get(route('media.whisparr.show', ['id' => 11]))
        ->assertRedirect(route('media.whisparr.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'No active Whisparr connection configured.');
});

test('the Whisparr page HTML never carries the API key', function (): void {
    whisparrPageConnection();
    Http::fake([
        'whisparr.local:6969/api/v3/movie' => Http::response([]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([]),
    ]);

    $response = $this->actingAs($this->admin)->get(route('media.whisparr.index'));
    $response->assertOk();

    expect($response->getContent())->not->toContain('whisparr-secret-key');

    // R15: the initial render never evaluates a deferred prop's closure, so
    // the assertion above alone proves nothing about `library`/
    // `qualityProfiles` — load both deferred groups and check their actual
    // payload for the key too.
    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps('default', function ($reload): void {
            expect(json_encode($reload->toArray()))->not->toContain('whisparr-secret-key');
        })
        ->loadDeferredProps('qualityProfiles', function ($reload): void {
            expect(json_encode($reload->toArray()))->not->toContain('whisparr-secret-key');
        }));
});

test('members and viewers cannot open the Whisparr pages', function (bool $member): void {
    whisparrPageConnection();
    // The factory's default role is viewer; member() is the named state.
    $user = $member ? User::factory()->member()->create() : User::factory()->create();

    $this->actingAs($user)->get(route('media.whisparr.index'))->assertForbidden();
    $this->actingAs($user)->get(route('media.whisparr.show', ['id' => 11]))->assertForbidden();
})->with([
    'member' => [true],
    'viewer' => [false],
]);
