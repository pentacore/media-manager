<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('searches the Sonarr series catalog when service is sonarr', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Severance', 'year' => 2022, 'tvdbId' => 999001],
        ]),
    ]);

    $result = json_decode((new SearchMediaTool)->handle(new Request(['service' => 'sonarr', 'query' => 'Severance'])), true);

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Severance');
});

test('searches the Radarr movie catalog when service is radarr', function (): void {
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);
    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['title' => 'Inception', 'year' => 2010, 'tmdbId' => 27205],
        ]),
    ]);

    $result = json_decode((new SearchMediaTool)->handle(new Request(['service' => 'radarr', 'query' => 'Inception'])), true);

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Inception');
});

test('searches the Whisparr lookup when service is whisparr', function (): void {
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);
    Http::fake([
        'whisparr.local:6969/api/v3/movie/lookup*' => Http::response([['title' => 'X']]),
    ]);

    $result = json_decode((new SearchMediaTool)->handle(new Request(['service' => 'whisparr', 'query' => 'X'])), true);

    expect($result)->toHaveCount(1);
});

test('returns tool_failed for an unknown service', function (): void {
    $result = json_decode((new SearchMediaTool)->handle(new Request(['service' => 'emby', 'query' => 'x'])), true);

    expect($result['error'])->toBe('tool_failed');
});

test('returns tool_failed when no connection is configured', function (): void {
    $result = json_decode((new SearchMediaTool)->handle(new Request(['service' => 'sonarr', 'query' => 'Severance'])), true);

    expect($result['error'])->toBe('tool_failed');
});

test('risk is Read', function (): void {
    expect((new SearchMediaTool)->risk())->toBe(Risk::Read);
});
