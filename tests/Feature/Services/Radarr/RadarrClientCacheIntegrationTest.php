<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);
});

test('searchMovies hits HTTP only once when called twice with the same term', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['title' => 'Inception', 'tmdbId' => 27205],
        ]),
    ]);

    $client = new RadarrClient($this->connection);
    $client->searchMovies('Inception');
    $client->searchMovies('Inception');

    Http::assertSentCount(1);
});

test('searchMovies hits HTTP twice for different terms', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['title' => 'Anything', 'tmdbId' => 1],
        ]),
    ]);

    $client = new RadarrClient($this->connection);
    $client->searchMovies('Inception');
    $client->searchMovies('Interstellar');

    Http::assertSentCount(2);
});

test('getMovieById caches per-id', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response(['id' => 42, 'title' => 'X']),
    ]);

    $client = new RadarrClient($this->connection);
    $client->getMovieById(42);
    $client->getMovieById(42);

    Http::assertSentCount(1);
});

test('getQualityProfiles caches inherited ArrClient call', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'HD-1080p'],
        ]),
    ]);

    $client = new RadarrClient($this->connection);
    $client->getQualityProfiles();
    $client->getQualityProfiles();

    Http::assertSentCount(1);
});

test('getRootFolders caches inherited ArrClient call', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/movies'],
        ]),
    ]);

    $client = new RadarrClient($this->connection);
    $client->getRootFolders();
    $client->getRootFolders();

    Http::assertSentCount(1);
});
