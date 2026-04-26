<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test-api-key',
    ]);

    $this->client = new ProwlarrClient($this->connection);
});

test('searchIndexers returns release rows for the given query', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/search?query=Severance*' => Http::response([
            ['title' => 'Severance.S02E01.1080p.WEB-DL', 'indexer' => 'Demo', 'size' => 1_500_000_000, 'seeders' => 50, 'age' => 1, 'downloadUrl' => 'http://demo/foo'],
            ['title' => 'Severance.S02E01.720p.WEB-DL', 'indexer' => 'Demo', 'size' => 800_000_000, 'seeders' => 12, 'age' => 1, 'downloadUrl' => 'http://demo/bar'],
        ]),
    ]);

    $result = $this->client->searchIndexers('Severance');

    expect($result)->toHaveCount(2);
    expect($result[0]['title'])->toBe('Severance.S02E01.1080p.WEB-DL');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v1/search')
        && $request->data()['query'] === 'Severance');
});

test('searchIndexers passes optional type and indexerIds parameters', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([]),
    ]);

    $this->client->searchIndexers('Severance', ['type' => 'tv-search', 'indexerIds' => [1, 2]]);

    Http::assertSent(fn (Request $request): bool => $request->data()['type'] === 'tv-search'
        && $request->data()['indexerIds'] === [1, 2]);
});

test('listIndexers returns all configured indexers', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([
            ['id' => 1, 'name' => 'Demo Indexer', 'enable' => true, 'priority' => 25],
            ['id' => 2, 'name' => 'Other', 'enable' => false, 'priority' => 50],
        ]),
    ]);

    $result = $this->client->listIndexers();

    expect($result)->toHaveCount(2);
    expect($result[0]['name'])->toBe('Demo Indexer');
});

test('testIndexer POSTs to the indexer test endpoint', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/indexer/42/test' => Http::response([], 200),
    ]);

    $result = $this->client->testIndexer(42);

    expect($result)->toBe(['success' => true, 'errors' => []]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v1/indexer/42/test'));
});

test('testIndexer surfaces validation failures from Prowlarr', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/indexer/42/test' => Http::response([
            ['propertyName' => 'baseUrl', 'errorMessage' => 'Unable to connect to indexer'],
        ], 400),
    ]);

    $result = $this->client->testIndexer(42);

    expect($result['success'])->toBeFalse();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['errorMessage'])->toBe('Unable to connect to indexer');
});

test('getIndexerStats returns aggregate stats', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/indexerstats*' => Http::response([
            'indexers' => [
                ['indexerId' => 1, 'numberOfQueries' => 100, 'numberOfGrabs' => 12],
            ],
        ]),
    ]);

    $result = $this->client->getIndexerStats();

    expect($result['indexers'])->toHaveCount(1);
});

test('getSystemStatus is inherited from ArrClient and uses v1 path', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/system/status' => Http::response(['version' => '1.20.0']),
    ]);

    $result = $this->client->getSystemStatus();

    expect($result['version'])->toBe('1.20.0');
});
