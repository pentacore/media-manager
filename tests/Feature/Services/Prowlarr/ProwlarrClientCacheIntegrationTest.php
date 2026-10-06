<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Arr\ArrUnexpectedResponse;
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
    $client->getIndexerStats();
    $client->getIndexerStats();

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

test('a 200 Prowlarr read that is not JSON data is an upstream failure and is never cached', function (string $method, array $arguments, string $path): void {
    Http::fake(['prowlarr.local:9696'.$path => Http::response('<html><body>Sign in</body></html>', 200, ['Content-Type' => 'text/html'])]);
    $client = new ProwlarrClient($this->connection);

    expect(fn (): array => $client->{$method}(...$arguments))
        ->toThrow(ArrUnexpectedResponse::class, 'Prowlarr answered with a body that is not JSON data.')
        ->and(fn (): array => $client->{$method}(...$arguments))
        ->toThrow(ArrUnexpectedResponse::class);

    // Both calls went upstream: the failure never entered the cache.
    Http::assertSentCount(2);
})->with([
    'indexer search' => ['searchIndexers', ['Severance'], '/api/v1/search*'],
    'indexer list' => ['listIndexers', [], '/api/v1/indexer'],
    'indexer stats' => ['getIndexerStats', [], '/api/v1/indexerstats*'],
]);
