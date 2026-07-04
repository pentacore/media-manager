<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('risk is Read', function (): void {
    expect((new GetDownloadQueueTool)->risk())->toBe(Risk::Read);
});

test('returns slim queue projection for sonarr with stuck flag', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'is_active' => true,
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response([
            'totalRecords' => 2,
            'records' => [
                [
                    'id' => 11,
                    'downloadId' => 'HASH-A',
                    'title' => 'Show.S01E01.1080p',
                    'status' => 'completed',
                    'trackedDownloadStatus' => 'warning',
                    'trackedDownloadState' => 'importPending',
                    'protocol' => 'usenet',
                    'indexer' => 'NZBgeek',
                    'size' => 1234567,
                    'timeleft' => '00:00:00',
                    'statusMessages' => [
                        ['title' => 'Show.S01E01', 'messages' => ['Not an upgrade for existing episode file(s)']],
                    ],
                    'series' => ['id' => 5, 'title' => 'Show'],
                    'episode' => ['id' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1],
                ],
                [
                    'id' => 12,
                    'downloadId' => 'HASH-B',
                    'title' => 'Show.S01E02.1080p',
                    'status' => 'downloading',
                    'trackedDownloadStatus' => 'ok',
                    'trackedDownloadState' => 'downloading',
                    'protocol' => 'torrent',
                    'indexer' => 'IPT',
                    'size' => 999,
                    'timeleft' => '00:12:00',
                    'statusMessages' => [],
                ],
            ],
        ]),
    ]);

    $result = json_decode(
        (new GetDownloadQueueTool)->handle(new Request(['service' => 'sonarr'])),
        true,
    );

    expect($result['service'])->toBe('sonarr')
        ->and($result['total'])->toBe(2)
        ->and($result['items'][0]['download_id'])->toBe('HASH-A')
        ->and($result['items'][0]['stuck'])->toBeTrue()
        ->and($result['items'][0]['status_messages'])->toContain('Not an upgrade for existing episode file(s)')
        ->and($result['items'][1]['stuck'])->toBeFalse();
});

test('rejects unknown service', function (): void {
    $result = json_decode(
        (new GetDownloadQueueTool)->handle(new Request(['service' => 'emby'])),
        true,
    );

    expect($result['error'])->toBe('tool_failed');
});

test('stuck_only filter drops healthy items', function (): void {
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'is_active' => true,
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/queue*' => Http::response([
            'totalRecords' => 2,
            'records' => [
                ['id' => 1, 'downloadId' => 'A', 'title' => 'M1', 'status' => 'completed', 'trackedDownloadStatus' => 'warning', 'statusMessages' => [['messages' => ['stuck']]], 'movie' => ['id' => 9, 'title' => 'M1']],
                ['id' => 2, 'downloadId' => 'B', 'title' => 'M2', 'status' => 'downloading', 'trackedDownloadStatus' => 'ok', 'statusMessages' => []],
            ],
        ]),
    ]);

    $result = json_decode(
        (new GetDownloadQueueTool)->handle(new Request(['service' => 'radarr', 'stuck_only' => true])),
        true,
    );

    expect($result['returned'])->toBe(1)
        ->and($result['items'][0]['download_id'])->toBe('A');
});
