<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Arr\StuckImportInspector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('inspect returns the assessment and the file descriptions', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([[
        'path' => '/dl/show.s01e01.mkv',
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
        'series' => ['id' => 5],
        'episodes' => [['id' => 11]],
        'rejections' => [['reason' => 'Not an upgrade for existing episode file(s)']],
    ]])]);

    $inspection = resolve(StuckImportInspector::class)->inspect($sonarr, 'sonarr', 'dl-1');

    expect($inspection)
        ->service->toBe('sonarr')
        ->download_id->toBe('dl-1')
        ->total->toBe(1)
        ->importable->toBe(1)
        ->fully_mapped->toBeTrue()
        ->and($inspection['files'])->toHaveCount(1);
});

test('inspect lets a failed lookup surface to the caller', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => fn () => throw new ConnectionException('down')]);

    resolve(StuckImportInspector::class)->inspect($sonarr, 'sonarr', 'dl-1');
})->throws(ConnectionException::class);
