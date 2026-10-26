<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\AuditSeriesSubtitlesTool;
use App\Models\ServiceConnection;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']],
        ],
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'allowed',
        'subtitle_check' => ['enabled' => true, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
});

/**
 * @param  array<string, mixed>  $seriesOverrides
 */
function fakeAuditToolSonarr(array $seriesOverrides = []): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(array_replace([
            'id' => 42, 'title' => 'Show', 'path' => '/tv/Show', 'seriesType' => 'standard',
        ], $seriesOverrides)),
        'sonarr.local:8989/api/v3/episodefile*' => Http::response([
            ['id' => 501, 'seriesId' => 42, 'mediaInfo' => ['subtitles' => 'Japanese']],
            ['id' => 502, 'seriesId' => 42, 'mediaInfo' => ['subtitles' => 'English/Japanese']],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501, 'monitored' => true, 'hasFile' => true],
            ['id' => 102, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 2, 'episodeFileId' => 502, 'monitored' => true, 'hasFile' => true],
        ]),
    ]);
}

test('the audit tool is a read tool', function (): void {
    expect(new AuditSeriesSubtitlesTool()->risk())->toBe(Risk::Read);
});

test('the audit tool reports the series buckets', function (): void {
    fakeAuditToolSonarr();

    $result = json_decode(new AuditSeriesSubtitlesTool()->handle(new Request([
        'series_id' => 42, 'season_number' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['counts']['missing'])->toBe(1)
        ->and($result['counts']['ok'])->toBe(1)
        ->and($result['missing'])->toBe([['label' => 'S01E01', 'missing_languages' => ['eng']]]);
});

test('the audit tool rejects a missing series id', function (): void {
    $result = json_decode(new AuditSeriesSubtitlesTool()->handle(new Request([
        'season_number' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['error'])->toBe('invalid_arguments');
});

test('the audit tool explains an unconfigured root folder', function (): void {
    fakeAuditToolSonarr(['path' => '/elsewhere/Show']);

    $result = json_decode(new AuditSeriesSubtitlesTool()->handle(new Request([
        'series_id' => 42, 'season_number' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['error'])->toBe('unconfigured_root_scope')
        ->and($result['message'])->toBe('The series root folder has no anime/tv scope configured. Tell the user to set it in the Sonarr connection settings.');
});
