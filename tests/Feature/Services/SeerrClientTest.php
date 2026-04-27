<?php

use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'seerr-test-key',
    ]);

    $this->client = new SeerrClient($this->connection);
});

test('sends X-Api-Key header with requests', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/status' => Http::response(['version' => '2.0.0']),
    ]);

    $this->client->getStatus();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'seerr-test-key'));
});

test('getStatus returns status data', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/status' => Http::response([
            'version' => '2.0.0',
            'commitTag' => 'abc123',
        ]),
    ]);

    $result = $this->client->getStatus();

    expect($result['version'])->toBe('2.0.0');
});

test('getRequests returns paginated requests', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['pages' => 1, 'results' => 2],
            'results' => [
                ['id' => 1, 'type' => 'movie', 'status' => 2],
                ['id' => 2, 'type' => 'tv', 'status' => 1],
            ],
        ]),
    ]);

    $result = $this->client->getRequests(['take' => 10, 'skip' => 0]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'take=10'));

    expect($result['results'])->toHaveCount(2);
});

test('getRequestById returns single request', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request/42' => Http::response([
            'id' => 42,
            'type' => 'movie',
            'media' => ['tmdbId' => 27205],
        ]),
    ]);

    $result = $this->client->getRequestById(42);

    expect($result['id'])->toBe(42);
});

test('deleteRequest sends DELETE', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request/42' => Http::response([], 204),
    ]);

    $this->client->deleteRequest(42);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v1/request/42'));
});

test('search encodes query and returns results', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                ['id' => 1, 'mediaType' => 'movie', 'title' => 'Inception'],
            ],
        ]),
    ]);

    $result = $this->client->search('inception');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'query=inception'));

    expect($result['results'])->toHaveCount(1);
});

test('getUsers returns user list', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/user*' => Http::response([
            'pageInfo' => ['pages' => 1, 'results' => 1],
            'results' => [
                ['id' => 1, 'displayName' => 'Admin'],
            ],
        ]),
    ]);

    $result = $this->client->getUsers();

    expect($result['results'])->toHaveCount(1);
});

test('throws on server error', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/status' => Http::response([], 500),
    ]);

    $this->client->getStatus();
})->throws(RequestException::class);

test('updateRequestStatus sends POST to approve or decline path', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request/42/approve' => Http::response(['id' => 42, 'status' => 2]),
    ]);

    $result = $this->client->updateRequestStatus(42, 'approve');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/request/42/approve'));

    expect($result['id'])->toBe(42);
});

test('updateRequestStatus rejects invalid status values', function (): void {
    expect(fn () => $this->client->updateRequestStatus(42, 'bogus'))
        ->toThrow(InvalidArgumentException::class);
});

test('retryRequest sends POST to retry endpoint', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request/42/retry' => Http::response(['id' => 42, 'status' => 1]),
    ]);

    $result = $this->client->retryRequest(42);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/request/42/retry'));

    expect($result['id'])->toBe(42);
});

test('getRequestCount returns count summary', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 10,
            'movie' => 4,
            'tv' => 6,
            'pending' => 2,
            'approved' => 3,
        ]),
    ]);

    $result = $this->client->getRequestCount();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/api/v1/request/count'));

    expect($result['total'])->toBe(10);
    expect($result['pending'])->toBe(2);
});

test('getMovieDetails GETs /movie/{tmdbId}', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/movie/603' => Http::response(['id' => 603, 'title' => 'The Matrix']),
    ]);

    $result = $this->client->getMovieDetails(603);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/api/v1/movie/603'));

    expect($result['title'])->toBe('The Matrix');
});

test('getTvDetails GETs /tv/{tmdbId}', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/tv/1396' => Http::response(['id' => 1396, 'name' => 'Breaking Bad']),
    ]);

    $result = $this->client->getTvDetails(1396);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/api/v1/tv/1396'));

    expect($result['name'])->toBe('Breaking Bad');
});

test('discoverMovies GETs /discover/movies with query params', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/discover/movies*' => Http::response([
            'page' => 1,
            'results' => [['id' => 603, 'title' => 'The Matrix']],
        ]),
    ]);

    $result = $this->client->discoverMovies(['genre' => '28', 'sortBy' => 'popularity.desc', 'page' => 2]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v1/discover/movies')
        && str_contains($request->url(), 'genre=28')
        && str_contains($request->url(), 'sortBy=popularity.desc')
        && str_contains($request->url(), 'page=2'));

    expect($result['results'])->toHaveCount(1);
});

test('discoverTv GETs /discover/tv with query params', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/discover/tv*' => Http::response([
            'page' => 1,
            'results' => [['id' => 1396, 'name' => 'Breaking Bad']],
        ]),
    ]);

    $result = $this->client->discoverTv(['genre' => '18', 'sortBy' => 'vote_average.desc']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v1/discover/tv')
        && str_contains($request->url(), 'genre=18')
        && str_contains($request->url(), 'sortBy=vote_average.desc'));

    expect($result['results'])->toHaveCount(1);
});
