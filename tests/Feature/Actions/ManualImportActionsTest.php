<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Arr\ManualImportActions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
});

test('resolve_manual_import enumerates candidates and posts ManualImport', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/manualimport*' => Http::response([[
            'path' => '/dl/show.s01e01.mkv',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'series' => ['id' => 5],
            'episodes' => [['id' => 11]],
        ]]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 1], 201),
    ]);

    $request = ActionRequest::factory()->create([
        'type' => 'resolve_manual_import',
        'target_service' => 'sonarr',
        'payload' => ['service' => 'sonarr', 'download_id' => 'dl-1'],
    ]);

    $result = resolve(ManualImportActions::class)->execute($request);

    expect($result)->toMatchArray(['service' => 'sonarr', 'download_id' => 'dl-1', 'files_imported' => 1]);

    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains((string) $r->url(), '/api/v3/command')
        && $r['name'] === 'ManualImport'
        && $r['importMode'] === 'auto');
});

test('throws when no files are importable', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([])]);

    $request = ActionRequest::factory()->create([
        'type' => 'resolve_manual_import',
        'target_service' => 'sonarr',
        'payload' => ['service' => 'sonarr', 'download_id' => 'dl-1'],
    ]);

    expect(fn (): array => resolve(ManualImportActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class);
});

test('throws when download_id is missing', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'resolve_manual_import',
        'target_service' => 'sonarr',
        'payload' => ['service' => 'sonarr'],
    ]);

    expect(fn (): array => resolve(ManualImportActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class);
});

test('throws for an unknown service', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'resolve_manual_import',
        'target_service' => 'emby',
        'payload' => ['service' => 'emby', 'download_id' => 'x'],
    ]);

    expect(fn (): array => resolve(ManualImportActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class);
});

test('throws for the wrong action type', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'delete_series', 'payload' => []]);

    expect(fn (): array => resolve(ManualImportActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class);
});
