<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Sonarr\SearchSeriesTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

test('searches Sonarr series catalog by query', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Severance', 'year' => 2022, 'tvdbId' => 999001],
        ]),
    ]);

    $result = json_decode((new SearchSeriesTool)->handle(new Request(['query' => 'Severance'])), true);

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Severance');
});

test('returns tool_failed when no Sonarr connection is configured', function (): void {
    $this->connection->delete();

    $result = json_decode((new SearchSeriesTool)->handle(new Request(['query' => 'Severance'])), true);

    expect($result['error'])->toBe('tool_failed');
});

test('risk is Read', function (): void {
    expect((new SearchSeriesTool)->risk())->toBe(Risk::Read);
});
