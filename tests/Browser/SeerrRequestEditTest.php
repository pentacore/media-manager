<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * One pending Seerr movie request (id 1, "Example Movie"). The single-request
 * read is registered before the list pattern, which would otherwise answer it
 * (Http::fake matches the first-registered pattern).
 */
function seerrRequestEditFakeSeerr(): void
{
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 1, 'pending' => 1, 'approved' => 0, 'declined' => 0, 'movie' => 1, 'tv' => 0]),
        'seerr.local:5055/api/v1/request/1' => Http::response([
            'id' => 1, 'type' => 'movie', 'profileId' => 7, 'rootFolder' => '/movies', 'serverId' => 0, 'is4k' => false,
            'media' => ['id' => 100, 'mediaType' => 'movie', 'tmdbId' => 9001],
        ]),
        'seerr.local:5055/api/v1/movie/9001' => Http::response(['id' => 9001, 'title' => 'Example Movie', 'posterPath' => '/example.jpg']),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1],
            'results' => [[
                'id' => 1,
                'status' => 1,
                'type' => 'movie',
                'media' => ['mediaType' => 'movie', 'tmdbId' => 9001],
                'requestedBy' => ['displayName' => 'Example User'],
                'createdAt' => '2026-10-01T00:00:00Z',
            ]],
        ]),
    ]);
}

test('the edit dialog says in words why the options could not load', function (): void {
    seerrRequestEditFakeSeerr();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-request-card="1"]', 'Example Movie')
        ->click('[data-request-card="1"] [data-request-edit]')
        ->assertSeeIn('[data-request-edit-error]', 'No active Radarr connection configured.')
        ->assertDontSeeIn('[data-request-edit-error]', 'no_arr_for_media_type')
        ->assertNoSmoke();
});

test('the edit dialog loads the profiles and root folders Radarr lists', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 7, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/rootfolder' => Http::response([['path' => '/movies', 'freeSpace' => 1_000_000_000]]),
    ]);
    seerrRequestEditFakeSeerr();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-request-card="1"] [data-request-edit]')
        ->assertSee('Quality profile')
        ->assertSee('HD-1080p')
        ->assertMissing('[data-request-edit-error]')
        ->assertNoSmoke();
});
