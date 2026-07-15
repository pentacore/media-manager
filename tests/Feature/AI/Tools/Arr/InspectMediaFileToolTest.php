<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\InspectMediaFileTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

test('the inspect tool is a read tool', function (): void {
    expect((new InspectMediaFileTool)->risk())->toBe(Risk::Read);
});

test('the inspect tool returns a compact snapshot for a resolved episode', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'Trusted.Anime.S01E01.CR', 'mediaInfo' => ['subtitles' => 'English / Japanese'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $result = json_decode((new InspectMediaFileTool)->handle(new Request([
        'service' => 'sonarr',
        'item_id' => 42,
        'season_number' => 1,
        'episode_number' => 1,
        'absolute_episode_number' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['ambiguous'])->toBeFalse()
        ->and($result['scope'])->toBe('anime')
        ->and($result['episode_file_ids'])->toBe([501])
        ->and($result['subtitles'])->toBe(['eng', 'jpn']);
});

test('the inspect tool surfaces ambiguity rather than guessing', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
            ['id' => 102, 'seasonNumber' => 1, 'episodeNumber' => 2, 'episodeFileId' => 502],
        ]),
    ]);

    $result = json_decode((new InspectMediaFileTool)->handle(new Request([
        'service' => 'sonarr',
        'item_id' => 42,
        'season_number' => 1,
        'episode_number' => null,
        'absolute_episode_number' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['ambiguous'])->toBeTrue()
        ->and($result['reason'])->toBe('multiple_episodes');
});

test('the inspect tool rejects an unknown service', function (): void {
    $result = json_decode((new InspectMediaFileTool)->handle(new Request([
        'service' => 'lidarr',
        'item_id' => 1,
        'season_number' => null,
        'episode_number' => null,
        'absolute_episode_number' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['error'])->toBe('tool_failed');
});
