<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'k',
    ]);
});

test('searchIndexers hits HTTP only once when called twice with the same query', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([
            ['title' => 'release 1'],
        ]),
    ]);

    $client = new ProwlarrClient($this->connection);
    $client->searchIndexers('Severance');
    $client->searchIndexers('Severance');

    Http::assertSentCount(1);
});

test('searchIndexers hits HTTP twice for different queries', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([
            ['title' => 'anything'],
        ]),
    ]);

    $client = new ProwlarrClient($this->connection);
    $client->searchIndexers('Severance');
    $client->searchIndexers('Andor');

    Http::assertSentCount(2);
});

test('listIndexers caches', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([
            ['id' => 1, 'name' => 'Indexer 1'],
        ]),
    ]);

    $client = new ProwlarrClient($this->connection);
    $client->listIndexers();
    $client->listIndexers();

    Http::assertSentCount(1);
});

test('getIndexerStats caches per-arg-shape', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/indexerstats*' => Http::response([
            'numberOfQueries' => 100,
        ]),
    ]);

    $client = new ProwlarrClient($this->connection);
    $client->getIndexerStats(null, null);
    $client->getIndexerStats(null, null);

    Http::assertSentCount(1);
});

test('getQualityProfiles caches inherited ArrClient call', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'Any'],
        ]),
    ]);

    $client = new ProwlarrClient($this->connection);
    $client->getQualityProfiles();
    $client->getQualityProfiles();

    Http::assertSentCount(1);
});
