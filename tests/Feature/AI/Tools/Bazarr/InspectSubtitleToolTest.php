<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Bazarr\InspectSubtitleTool;
use App\Enums\BazarrServiceRole;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('i', 32)));
    config()->set('mediamanager.cache.store', 'array');
    Http::preventStrayRequests();
});

test('the inspect subtitle tool is read only and returns a sanitized bounded item', function (): void {
    [$bazarr] = inspectToolConnections();
    fakeInspectToolMovie();

    $result = json_decode((new InspectSubtitleTool)->handle(new Request([
        'bazarr_connection_id' => $bazarr->id,
        'media_type' => 'movie',
        'media_id' => 801,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect((new InspectSubtitleTool)->risk())->toBe(Risk::Read)
        ->and($result['item']['media_id'])->toBe(801)
        ->and($result['item']['subtitle_tracks'][0]['language'])->toBe('eng')
        ->and(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain('/media/')
        ->not->toContain('bazarr-secret')
        ->not->toContain('subtitle contents');
});

test('the inspect tool requires the exact active Bazarr connection', function (): void {
    $inactive = ServiceConnection::factory()->bazarr()->inactive()->create();

    $result = json_decode((new InspectSubtitleTool)->handle(new Request([
        'bazarr_connection_id' => $inactive->id,
        'media_type' => 'movie',
        'media_id' => 801,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['error'])->toBe('tool_failed');
});

/**
 * @return array{ServiceConnection, ServiceConnection}
 */
function inspectToolConnections(): array
{
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);

    return [$bazarr, $radarr];
}

function fakeInspectToolMovie(): void
{
    Http::fake(fn (HttpRequest $httpRequest) => match (parse_url($httpRequest->url(), PHP_URL_PATH)) {
        '/api/movies' => Http::response(['data' => [[
            'radarrId' => 801,
            'title' => 'Example Movie',
            'sceneName' => 'Example.Movie.2024.1080p',
            'path' => '/media/movies/Example Movie',
            'subtitles' => [[
                'code3' => 'eng',
                'path' => '/media/movies/Example.Movie.en.srt',
                'forced' => false,
                'hi' => false,
                'content' => 'subtitle contents',
            ]],
        ]], 'total' => 1]),
        '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
        default => Http::response(['data' => []]),
    });
}
