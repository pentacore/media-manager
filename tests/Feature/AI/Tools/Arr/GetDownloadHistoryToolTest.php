<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Models\ServiceConnection;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('risk is Read', function (): void {
    expect((new GetDownloadHistoryTool)->risk())->toBe(Risk::Read);
});

test('returns slim history projection and forwards downloadId filter', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'is_active' => true,
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/history*' => Http::response([
            'page' => 1,
            'pageSize' => 20,
            'totalRecords' => 1,
            'records' => [
                [
                    'id' => 77,
                    'downloadId' => 'HASH-A',
                    'eventType' => 'grabbed',
                    'sourceTitle' => 'Show.S01E01.1080p.WEB',
                    'date' => '2026-07-01T10:00:00Z',
                    'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
                    'series' => ['title' => 'Show'],
                    'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1],
                    'data' => ['indexer' => 'NZBgeek'],
                ],
            ],
        ]),
    ]);

    $result = json_decode(
        (new GetDownloadHistoryTool)->handle(new Request([
            'service' => 'sonarr',
            'download_id' => 'HASH-A',
        ])),
        true,
    );

    expect($result['total'])->toBe(1)
        ->and($result['events'][0]['event_type'])->toBe('grabbed')
        ->and($result['events'][0]['download_id'])->toBe('HASH-A')
        ->and($result['events'][0]['quality'])->toBe('WEBDL-1080p')
        ->and($result['events'][0]['episode'])->toBe('S01E01');

    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'downloadId=HASH-A'));
});

test('caps page_size at 100', function (): void {
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'is_active' => true,
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/history*' => Http::response([
            'page' => 1, 'pageSize' => 100, 'totalRecords' => 0, 'records' => [],
        ]),
    ]);

    (new GetDownloadHistoryTool)->handle(new Request([
        'service' => 'radarr',
        'page_size' => 5000,
    ]));

    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'pageSize=100'));
});
