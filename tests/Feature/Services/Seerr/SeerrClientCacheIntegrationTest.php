<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]);
});

test('getRequests hits HTTP only once when called twice with the same params', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 0],
            'results' => [],
        ]),
    ]);

    $client = new SeerrClient($this->connection);
    $client->getRequests(['take' => 50, 'skip' => 0, 'sort' => 'added']);
    $client->getRequests(['take' => 50, 'skip' => 0, 'sort' => 'added']);

    Http::assertSentCount(1);
});

test('getRequests hits HTTP twice for different params', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 0],
            'results' => [],
        ]),
    ]);

    $client = new SeerrClient($this->connection);
    $client->getRequests(['take' => 50, 'skip' => 0]);
    $client->getRequests(['take' => 50, 'skip' => 50]);

    Http::assertSentCount(2);
});

test('getRequestById caches per-id', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request/42' => Http::response(['id' => 42, 'status' => 1]),
        'seerr.local:5055/api/v1/request/99' => Http::response(['id' => 99, 'status' => 2]),
    ]);

    $client = new SeerrClient($this->connection);
    $client->getRequestById(42);
    $client->getRequestById(42);
    $client->getRequestById(99);

    Http::assertSentCount(2);
});

test('search hits HTTP only once for the same query', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response(['results' => []]),
    ]);

    $client = new SeerrClient($this->connection);
    $client->search('Inception');
    $client->search('Inception');

    Http::assertSentCount(1);
});

test('discoverMovies hits HTTP only once for the same options', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/discover/movies*' => Http::response(['results' => []]),
    ]);

    $client = new SeerrClient($this->connection);
    $client->discoverMovies(['page' => 1, 'sortBy' => 'popularity']);
    $client->discoverMovies(['page' => 1, 'sortBy' => 'popularity']);

    Http::assertSentCount(1);
});

test('getMovieDetails caches per-tmdbId', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/movie/603' => Http::response(['id' => 603, 'title' => 'The Matrix']),
        'seerr.local:5055/api/v1/movie/27205' => Http::response(['id' => 27205, 'title' => 'Inception']),
    ]);

    $client = new SeerrClient($this->connection);
    $client->getMovieDetails(603);
    $client->getMovieDetails(603);
    $client->getMovieDetails(27205);

    Http::assertSentCount(2);
});
