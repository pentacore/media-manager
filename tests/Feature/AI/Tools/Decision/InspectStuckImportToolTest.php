<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Models\ServiceConnection;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
});

test('returns per-file mapping, episodes and raw rejections', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([
        [
            'path' => '/dl/show.s01e01.mkv',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'series' => ['id' => 5, 'title' => 'Demo'],
            'episodes' => [['id' => 11, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'rejections' => [['reason' => 'Found matching series via grab history, but series was matched by series id. Automatic import is not possible.']],
        ],
    ])]);

    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_ids' => ['dl-1'],
    ])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['downloads'][0]['fully_mapped'])->toBeTrue();
    expect($result['downloads'][0]['files'][0]['mapped'])->toBeTrue();
    expect($result['downloads'][0]['files'][0]['episodes'])->toBe(['S01E01']);
    expect($result['downloads'][0]['files'][0]['rejections'][0])->toContain('matched by series id');
});

test('inspection works even when the import capability is off (read-only)', function (): void {
    resolve(DecisionAgentSettings::class)->setAllowManualImport(false);
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([])]);

    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_ids' => ['dl-1'],
    ])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['downloads'][0]['importable'])->toBe(0);
});

test('rejects an invalid service', function (): void {
    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'plex', 'download_ids' => ['dl-1'],
    ])), true);

    expect($result['ok'])->toBeFalse();
    expect($result['reason'])->toBe('invalid_arguments')
        ->and($result['errors']['service'][0])->toBe('service must be "sonarr" or "radarr".');
});

test('inspects candidates on the connection that triggered the event when a decision run context is bound', function (): void {
    $second = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-b.local:8989', 'api_key' => 'k']);
    app()->instance(DecisionRunContext::class, new DecisionRunContext(null, 3, 'sonarr', [], $second->id));
    Http::fake(['sonarr-b.local:8989/api/v3/manualimport*' => Http::response([])]);

    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_ids' => ['dl-1'],
    ])), true);

    app()->forgetInstance(DecisionRunContext::class);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn (Illuminate\Http\Client\Request $sentRequest): bool => str_contains($sentRequest->url(), 'sonarr-b.local:8989/api/v3/manualimport'));
    Http::assertNotSent(fn (Illuminate\Http\Client\Request $sentRequest): bool => str_contains($sentRequest->url(), 'sonarr.local:8989'));
});

test('uses the active connection when no decision run context is bound', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-b.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([])]);

    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_ids' => ['dl-1'],
    ])), true);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn (Illuminate\Http\Client\Request $sentRequest): bool => str_contains($sentRequest->url(), 'sonarr.local:8989/api/v3/manualimport'));
});

test('the inspection describes itself without naming an acting tool only one caller has', function (): void {
    $description = (string) (new InspectStuckImportTool)->description();

    expect($description)->toContain('Read-only')
        ->not->toContain('ResolveManualImportTool')
        ->not->toContain('RemoveStuckDownloadTool');
});

test("a non-string argument is refused in the tool's own words", function (array $arguments, string $field, string $message): void {
    $result = json_decode((new InspectStuckImportTool)->handle(new Request($arguments)), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('invalid_arguments')
        ->and($result['errors'][$field][0])->toBe($message);
    Http::assertNothingSent();
})->with([
    'a numeric service' => [['service' => 5, 'download_ids' => ['dl-1']], 'service', 'service must be "sonarr" or "radarr".'],
    'a string instead of a list' => [['service' => 'sonarr', 'download_ids' => 'dl-1'], 'download_ids', 'download_ids must be a list of downloadIds (from the event payload).'],
    'a non-string id in the list' => [['service' => 'sonarr', 'download_ids' => [['dl-1']]], 'download_ids.0', 'every download id must be a string (from the event payload).'],
    'a duplicated id' => [['service' => 'sonarr', 'download_ids' => ['dl-1', 'dl-1']], 'download_ids.0', 'download_ids must not repeat an id.'],
    'an empty list' => [['service' => 'sonarr', 'download_ids' => []], 'download_ids', 'download_ids needs at least one downloadId (from the event payload).'],
    'more than twenty ids' => [['service' => 'sonarr', 'download_ids' => array_map(fn (int $i): string => "id{$i}", range(1, 21))], 'download_ids', 'download_ids takes at most 20 downloadIds per call.'],
]);

test('it inspects several downloads in one call', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/manualimport*downloadId=A*' => Http::response([]),
        'sonarr.local:8989/api/v3/manualimport*downloadId=B*' => Http::response('boom', 500),
    ]);

    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'sonarr',
        'download_ids' => ['A', 'B'],
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['service'])->toBe('sonarr')
        ->and($result['downloads'])->toHaveCount(2)
        ->and($result['downloads'][0])->toMatchArray(['download_id' => 'A', 'ok' => true])
        ->and($result['downloads'][1])->toMatchArray(['download_id' => 'B', 'ok' => false, 'reason' => 'lookup_failed']);
});
