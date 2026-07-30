<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Bazarr\RequestSubtitleOperationTool;
use App\Enums\AiMode;
use App\Enums\BazarrServiceRole;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
    config()->set('mediamanager.cache.store', 'array');
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed(ActionTypeConfigSeeder::class);
    resolve(AiSettings::class)->setMode(AiMode::Executive);
});

test('request tool is destructive and queues a server-inspected download request', function (): void {
    $serviceConnection = requestToolConnection();
    fakeRequestToolMovie();

    $result = json_decode((new RequestSubtitleOperationTool)->handle(new Request([
        'bazarr_connection_id' => $serviceConnection->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'operation' => 'download_best',
        'language' => 'ENG',
        'forced' => false,
        'hearing_impaired' => false,
        'candidate_fingerprint' => null,
        'subtitle_fingerprint' => null,
        'media_action' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect((new RequestSubtitleOperationTool)->risk())->toBe(Risk::Destructive)
        ->and($result['queued'])->toBeTrue()
        ->and(ActionRequest::query()->sole()->payload)->toMatchArray([
            'bazarr_connection_id' => $serviceConnection->id,
            'media_type' => 'movie',
            'language' => 'eng',
            'target_ids' => ['radarr_id' => 801],
        ]);
});

test('advisory mode blocks requests before any Bazarr call', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);
    $serviceConnection = requestToolConnection();

    $result = json_decode((new RequestSubtitleOperationTool)->handle(new Request([
        'bazarr_connection_id' => $serviceConnection->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'operation' => 'download_best',
        'language' => 'eng',
        'forced' => false,
        'hearing_impaired' => false,
        'candidate_fingerprint' => null,
        'subtitle_fingerprint' => null,
        'media_action' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['error'])->toBe('advisory_mode_blocks_destructive')
        ->and(ActionRequest::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a disabled Action Rule returns a non-queued result', function (): void {
    ActionTypeConfig::query()->where('type', 'bazarr_download_best')->update(['is_enabled' => false]);
    $serviceConnection = requestToolConnection();
    fakeRequestToolMovie();

    $result = json_decode((new RequestSubtitleOperationTool)->handle(new Request([
        'bazarr_connection_id' => $serviceConnection->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'operation' => 'download_best',
        'language' => 'eng',
        'forced' => false,
        'hearing_impaired' => false,
        'candidate_fingerprint' => null,
        'subtitle_fingerprint' => null,
        'media_action' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toMatchArray(['queued' => false, 'reason' => 'no_action_type_config'])
        ->and(ActionRequest::query()->count())->toBe(0);
});

function requestToolConnection(): ServiceConnection
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

function fakeRequestToolMovie(): void
{
    Http::fake(fn (HttpRequest $httpRequest) => match (parse_url($httpRequest->url(), PHP_URL_PATH)) {
        '/api/movies' => Http::response(['data' => [[
            'radarrId' => 801,
            'title' => 'Example Movie',
            'sceneName' => 'Example.Movie.2024.1080p',
            'path' => '/media/movies/Example Movie',
            'subtitles' => [],
        ]], 'total' => 1]),
        '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
        default => Http::response(['data' => []]),
    });
}
