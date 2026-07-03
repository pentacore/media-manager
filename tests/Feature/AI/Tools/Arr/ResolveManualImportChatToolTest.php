<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->seed(ActionTypeConfigSeeder::class);
    resolve(AiSettings::class)->withMode(AiMode::Executive);
});

function fakeSonarrManualImport(array $candidates): void
{
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'is_active' => true,
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/manualimport*' => Http::response($candidates),
    ]);
}

test('risk is Destructive', function (): void {
    expect((new ResolveManualImportChatTool)->risk())->toBe(Risk::Destructive);
});

test('fully mapped candidates queue a resolve_manual_import action request', function (): void {
    fakeSonarrManualImport([
        [
            'path' => '/downloads/Show.S01E01.mkv',
            'folderName' => 'Show.S01E01.1080p',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'series' => ['id' => 5, 'title' => 'Show'],
            'episodes' => [['id' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'rejections' => [],
            'releaseGroup' => 'GRP',
        ],
    ]);

    $result = json_decode(
        (new ResolveManualImportChatTool)->handle(new Request([
            'service' => 'sonarr',
            'download_id' => 'HASH-A',
            'reason' => 'File maps cleanly to Show S01E01, no rejections.',
        ])),
        true,
    );

    expect($result['queued'])->toBeTrue();

    $actionRequest = ActionRequest::findOrFail($result['action_request_id']);
    expect($actionRequest->type)->toBe('resolve_manual_import')
        ->and($actionRequest->payload['download_id'])->toBe('HASH-A')
        ->and($actionRequest->payload['assessment']['fully_mapped'])->toBeTrue();
});

test('partially mapped candidates force approval', function (): void {
    // Auto-approve in config; the partial mapping must still force Pending.
    DB::table('action_type_configs')
        ->where('type', 'resolve_manual_import')
        ->update(['requires_approval' => false, 'is_enabled' => true]);

    fakeSonarrManualImport([
        [
            'path' => '/downloads/Show.S01E01.mkv',
            'folderName' => 'Show.S01E01.1080p',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'series' => ['id' => 5, 'title' => 'Show'],
            'episodes' => [['id' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'rejections' => [],
        ],
        [
            'path' => '/downloads/unknown-file.mkv',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'rejections' => [['reason' => 'Unknown series']],
        ],
    ]);

    $result = json_decode(
        (new ResolveManualImportChatTool)->handle(new Request([
            'service' => 'sonarr',
            'download_id' => 'HASH-A',
            'reason' => 'One of two files maps.',
        ])),
        true,
    );

    expect($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeTrue();
});

test('nothing importable returns tool_failed without queueing', function (): void {
    fakeSonarrManualImport([
        [
            'path' => '/downloads/unknown-file.mkv',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'rejections' => [['reason' => 'Unknown series']],
        ],
    ]);

    $result = json_decode(
        (new ResolveManualImportChatTool)->handle(new Request([
            'service' => 'sonarr',
            'download_id' => 'HASH-A',
            'reason' => 'trying anyway',
        ])),
        true,
    );

    expect($result['error'])->toBe('tool_failed')
        ->and(ActionRequest::count())->toBe(0);
});

test('advisory mode blocks the tool', function (): void {
    resolve(AiSettings::class)->withMode(AiMode::Advisory);

    $result = json_decode(
        (new ResolveManualImportChatTool)->handle(new Request([
            'service' => 'sonarr',
            'download_id' => 'HASH-A',
            'reason' => 'x',
        ])),
        true,
    );

    expect($result['error'])->toBe('advisory_mode_blocks_destructive');
});
