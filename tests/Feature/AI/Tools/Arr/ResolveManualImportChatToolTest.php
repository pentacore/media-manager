<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
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
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
            ['downloadId' => 'HASH-A', 'title' => 'Show.S01E01.1080p', 'series' => ['title' => 'Show']],
        ]]),
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

test('missing download id or reason is reported as invalid arguments before any Sonarr call', function (array $arguments, string $invalidKey): void {
    Http::fake();

    $result = json_decode((new ResolveManualImportChatTool)->handle(new Request([
        'service' => 'sonarr',
        'download_id' => 'HASH-A',
        'reason' => 'File maps cleanly.',
        ...$arguments,
    ])), true);

    expect($result['error'])->toBe('invalid_arguments')
        ->and($result['errors'])->toHaveKey($invalidKey)
        ->and(ActionRequest::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing download id' => [['download_id' => ''], 'download_id'],
    'blank reason' => [['reason' => ' '], 'reason'],
]);

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

test('the queued import is described from the arr queue as requested in chat', function (): void {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Martin']));
    fakeSonarrManualImport([
        [
            'path' => '/downloads/Show.S01E01.mkv',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'series' => ['id' => 5, 'title' => 'Show'],
            'episodes' => [['id' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'rejections' => [],
        ],
    ]);

    $result = json_decode(
        (new ResolveManualImportChatTool)->handle(new Request([
            'service' => 'sonarr',
            'download_id' => 'HASH-A',
            'reason' => 'File maps cleanly.',
        ])),
        true,
    );

    $actionRequest = ActionRequest::findOrFail($result['action_request_id']);
    expect($actionRequest->title)->toBe('Import download "Show.S01E01.1080p"')
        ->and($actionRequest->description)->toBe('Requested in chat by Martin. Sonarr will import the 1 of 1 files it could match.')
        ->and($actionRequest->description_verified)->toBeTrue()
        ->and($actionRequest->origin)->toBe('chat');
});

test("a non-string download id or reason is refused in the tool's own words before any Sonarr call", function (array $arguments, string $field, string $message): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'is_active' => true]);
    Http::fake(['sonarr.local:8989/*' => Http::response([], 500)]);

    $result = json_decode((new ResolveManualImportChatTool)->handle(new Request([
        'service' => 'sonarr',
        'download_id' => 'HASH-A',
        'reason' => 'File maps cleanly.',
        ...$arguments,
    ])), true);

    expect($result['error'])->toBe('invalid_arguments')
        ->and($result['errors'][$field][0])->toBe($message)
        ->and(ActionRequest::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'a list as the download id' => [['download_id' => ['HASH-A']], 'download_id', 'download_id must be a string.'],
    'a number as the download id' => [['download_id' => 42], 'download_id', 'download_id must be a string.'],
    'a list as the reason' => [['reason' => ['x']], 'reason', 'reason must be a short plain-text explanation for the approver.'],
]);

function mappedAndExtraCandidates(array $extra = []): array
{
    return [
        [
            'path' => '/downloads/Show.S01E01.mkv',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'series' => ['id' => 5, 'title' => 'Show'],
            'episodes' => [['id' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'rejections' => [],
        ],
        array_replace([
            'path' => '/downloads/unknown-file.mkv',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'rejections' => [['reason' => 'Unknown series']],
        ], $extra),
    ];
}

test('excluding the unmapped file follows the action rule instead of forcing approval', function (): void {
    DB::table('action_type_configs')
        ->where('type', 'resolve_manual_import')
        ->update(['requires_approval' => false, 'is_enabled' => true]);
    fakeSonarrManualImport(mappedAndExtraCandidates());

    $result = json_decode((new ResolveManualImportChatTool)->handle(new Request([
        'service' => 'sonarr',
        'download_id' => 'HASH-A',
        'reason' => 'Leave out the unmapped extra.',
        'excluded_paths' => ['/downloads/unknown-file.mkv'],
    ])), true);

    $actionRequest = ActionRequest::findOrFail($result['action_request_id']);
    expect($actionRequest->requires_approval)->toBeFalse()
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Approved)
        ->and($actionRequest->payload['excluded_paths'])->toBe(['/downloads/unknown-file.mkv'])
        ->and($actionRequest->payload['assessment']['total'])->toBe(1);
});

test('a sample next to a mapped file is skipped without forcing approval', function (): void {
    DB::table('action_type_configs')
        ->where('type', 'resolve_manual_import')
        ->update(['requires_approval' => false, 'is_enabled' => true]);
    fakeSonarrManualImport(mappedAndExtraCandidates(['path' => '/downloads/Sample/sample.mkv']));

    $result = json_decode((new ResolveManualImportChatTool)->handle(new Request([
        'service' => 'sonarr',
        'download_id' => 'HASH-A',
        'reason' => 'Sample is skipped automatically.',
    ])), true);

    $actionRequest = ActionRequest::findOrFail($result['action_request_id']);
    expect($actionRequest->requires_approval)->toBeFalse()
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Approved)
        ->and($actionRequest->payload['excluded_paths'])->toBe([]);
});

test('excluded_paths must be a list of strings', function (): void {
    Http::fake();

    $result = json_decode((new ResolveManualImportChatTool)->handle(new Request([
        'service' => 'sonarr',
        'download_id' => 'HASH-A',
        'reason' => 'x',
        'excluded_paths' => 'not-a-list',
    ])), true);

    expect($result['error'])->toBe('invalid_arguments')
        ->and($result['errors'])->toHaveKey('excluded_paths');
    Http::assertNothingSent();
});
