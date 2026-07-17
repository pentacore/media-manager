<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Bazarr\SearchSubtitlesTool;
use App\Enums\BazarrServiceRole;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
    config()->set('mediamanager.cache.store', 'array');
    Http::preventStrayRequests();
});

test('search is read only and caps sanitized candidates at ten', function (): void {
    $serviceConnection = searchToolConnection();
    fakeSearchToolMovie();

    $result = json_decode((new SearchSubtitlesTool)->handle(new Request([
        'bazarr_connection_id' => $serviceConnection->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'limit' => 10,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect((new SearchSubtitlesTool)->risk())->toBe(Risk::Read)
        ->and($result['candidates'])->toHaveCount(10)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain('private-provider-id')
        ->not->toContain('provider.test')
        ->not->toContain('/media/');
});

test('search defaults to five and rejects limits outside one through ten', function (): void {
    $serviceConnection = searchToolConnection();
    fakeSearchToolMovie();
    $tool = new SearchSubtitlesTool;

    $default = json_decode($tool->handle(new Request([
        'bazarr_connection_id' => $serviceConnection->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'limit' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);
    $invalid = json_decode($tool->handle(new Request([
        'bazarr_connection_id' => $serviceConnection->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'limit' => 11,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($default['candidates'])->toHaveCount(5)
        ->and($invalid['error'])->toBe('tool_failed');
});

function searchToolConnection(): ServiceConnection
{
    $bazarr = ServiceConnection::factory()->bazarr()->create(['url' => 'http://bazarr.test']);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);

    return $bazarr;
}

function fakeSearchToolMovie(): void
{
    $candidates = array_map(static fn (int $index): array => [
        'provider' => 'Provider '.$index,
        'subtitle' => 'private-provider-id-'.$index,
        'url' => 'https://provider.test/'.$index,
        'language' => 'eng',
        'score' => 100 - $index,
        'release_info' => ['Example.Movie.2024.1080p'],
    ], range(1, 15));

    Http::fake(fn(HttpRequest $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/movies' => Http::response(['data' => [[
            'radarrId' => 801,
            'title' => 'Example Movie',
            'sceneName' => 'Example.Movie.2024.1080p',
            'path' => '/media/movies/Example Movie',
            'subtitles' => [],
        ]], 'total' => 1]),
        '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
        '/api/providers/movies' => Http::response(['data' => $candidates]),
        default => Http::response(['data' => []]),
    });
}
