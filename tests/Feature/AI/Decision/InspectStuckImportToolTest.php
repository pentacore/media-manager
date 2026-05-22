<?php

declare(strict_types=1);

use App\Ai\Decision\InspectStuckImportTool;
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
        'service' => 'sonarr', 'download_id' => 'dl-1',
    ])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['fully_mapped'])->toBeTrue();
    expect($result['files'][0]['mapped'])->toBeTrue();
    expect($result['files'][0]['episodes'])->toBe(['S01E01']);
    expect($result['files'][0]['rejections'][0])->toContain('matched by series id');
});

test('inspection works even when the import capability is off (read-only)', function (): void {
    resolve(DecisionAgentSettings::class)->setAllowManualImport(false);
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([])]);

    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1',
    ])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['importable'])->toBe(0);
});

test('rejects an invalid service', function (): void {
    $result = json_decode((new InspectStuckImportTool)->handle(new Request([
        'service' => 'plex', 'download_id' => 'dl-1',
    ])), true);

    expect($result['ok'])->toBeFalse();
    expect($result['reason'])->toBe('invalid_service');
});
