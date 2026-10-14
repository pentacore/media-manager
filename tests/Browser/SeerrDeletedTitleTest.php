<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('the requests page reads a completed request for a deleted title as Deleted and an available one as Available', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 2, 'pending' => 0, 'approved' => 0, 'declined' => 0, 'available' => 1, 'processing' => 0, 'completed' => 2]),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 2],
            'results' => [
                ['id' => 51, 'status' => 5, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 701, 'status' => 7], 'requestedBy' => ['displayName' => 'Example Viewer']],
                ['id' => 52, 'status' => 5, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 702, 'status' => 5], 'requestedBy' => ['displayName' => 'Example Viewer']],
            ],
        ]),
        'seerr.local:5055/api/v1/movie/701' => Http::response(['id' => 701, 'title' => 'Example First Movie', 'posterPath' => '/first.jpg']),
        'seerr.local:5055/api/v1/movie/702' => Http::response(['id' => 702, 'title' => 'Example Second Movie', 'posterPath' => '/second.jpg']),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.requests.index', ['status' => 'all'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-request-card="51"]', 'Deleted')
        ->assertSeeIn('[data-request-card="52"]', 'Available')
        ->assertNoSmoke();
});

test('my requests read a completed request for a deleted title as Deleted', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'viewer@example.com']]]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1, 'results' => 1, 'pageSize' => 20], 'results' => [
            ['id' => 61, 'status' => 5, 'type' => 'movie', 'media' => ['tmdbId' => 703, 'mediaType' => 'movie', 'status' => 7], 'requestedBy' => ['id' => 7]],
        ]]),
        'seerr.local:5055/api/v1/movie/703' => Http::response(['id' => 703, 'title' => 'Example Third Movie', 'posterPath' => '/third.jpg']),
    ]);
    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']));

    visit(route('media.requests.mine', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-my-request="61"]', 'Deleted')
        ->assertNoSmoke();
});
