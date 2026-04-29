<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Prowlarr\ListIndexersTool;
use App\Ai\Tools\Prowlarr\SearchIndexersTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

test('SearchIndexersTool returns release rows for the given query', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([
            ['title' => 'Demo.S01E01.1080p', 'indexer' => 'Demo', 'size' => 1_000_000_000, 'seeders' => 10],
            ['title' => 'Demo.S01E01.720p', 'indexer' => 'Demo', 'size' => 500_000_000, 'seeders' => 5],
        ]),
    ]);

    $result = json_decode((new SearchIndexersTool)->handle(new Request(['query' => 'Demo'])), true);

    expect($result)->toHaveCount(2);
    expect($result[0]['title'])->toBe('Demo.S01E01.1080p');
});

test('SearchIndexersTool returns tool_failed when no Prowlarr connection is configured', function (): void {
    $this->connection->delete();

    $result = json_decode((new SearchIndexersTool)->handle(new Request(['query' => 'Demo'])), true);

    expect($result['error'])->toBe('tool_failed');
});

test('SearchIndexersTool risk is Read', function (): void {
    expect((new SearchIndexersTool)->risk())->toBe(Risk::Read);
});

test('ListIndexersTool returns the configured indexers list', function (): void {
    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([
            ['id' => 1, 'name' => 'Indexer One', 'enable' => true, 'priority' => 25],
            ['id' => 2, 'name' => 'Indexer Two', 'enable' => false, 'priority' => 50],
        ]),
    ]);

    $result = json_decode((new ListIndexersTool)->handle(new Request([])), true);

    expect($result)->toHaveCount(2);
    expect($result[0]['name'])->toBe('Indexer One');
});

test('ListIndexersTool risk is Read', function (): void {
    expect((new ListIndexersTool)->risk())->toBe(Risk::Read);
});
