<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);
});

test('searchSeries hits HTTP only once when called twice with the same term', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Severance', 'tvdbId' => 1],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $client->searchSeries('Severance');
    $client->searchSeries('Severance');

    Http::assertSentCount(1);
});

test('searchSeries hits HTTP twice for different terms', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Anything', 'tvdbId' => 1],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $client->searchSeries('Severance');
    $client->searchSeries('Andor');

    Http::assertSentCount(2);
});

test('getSeriesById caches per-id', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'X']),
    ]);

    $client = new SonarrClient($this->connection);
    $client->getSeriesById(42);
    $client->getSeriesById(42);

    Http::assertSentCount(1);
});
