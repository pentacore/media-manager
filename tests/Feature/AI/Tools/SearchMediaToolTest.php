<?php

declare(strict_types=1);

use App\Ai\Tools\SearchMediaTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('fan-out search across all three services', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 1, 'title' => 'Breaking Bad', 'year' => 2008, 'overview' => 'Chemistry teacher'],
        ]),
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 2, 'title' => 'The Matrix', 'year' => 1999, 'overview' => 'Simulated reality'],
        ]),
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [['id' => 3, 'mediaType' => 'tv', 'title' => 'Inception', 'overview' => 'Dreams']],
        ]),
    ]);

    $tool = new SearchMediaTool;
    $result = json_decode((string) $tool->handle(new Request(['query' => 'any'])), true);

    expect($result['series'])->toHaveCount(1);
    expect($result['series'][0]['title'])->toBe('Breaking Bad');
    expect($result['movies'])->toHaveCount(1);
    expect($result['requests'])->toHaveCount(1);
});

test('failing service does not break others', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);

    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([['tvdbId' => 1, 'title' => 'OK', 'year' => 2024]]),
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response('boom', 500),
    ]);

    $result = json_decode((string) (new SearchMediaTool)->handle(new Request(['query' => 'any'])), true);

    expect($result['series'])->toHaveCount(1);
    expect($result['movies'])->toBe([]);
    expect($result['requests'])->toBe([]);
});

test('empty query returns error', function (): void {
    $result = json_decode((string) (new SearchMediaTool)->handle(new Request(['query' => ''])), true);
    expect($result)->toHaveKey('error');
});

test('description and schema are defined', function (): void {
    $tool = new SearchMediaTool;
    expect((string) $tool->description())->toContain('Sonarr');

    $reflection = new ReflectionMethod($tool, 'schema');
    expect($reflection->isPublic())->toBeTrue();
});
