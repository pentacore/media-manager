<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\MediaReplacement\MediaFileInspector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
});

function sonarrInspectorConnection(): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
    ]);
}

function radarrInspectorConnection(): ServiceConnection
{
    return ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'test',
        'is_active' => true,
    ]);
}

test('inspects a Sonarr anime episode into a compact snapshot', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42,
            'title' => 'Trusted Anime',
            'seriesType' => 'anime',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'absoluteEpisodeNumber' => 1, 'episodeFileId' => 501],
            ['id' => 102, 'seasonNumber' => 1, 'episodeNumber' => 2, 'absoluteEpisodeNumber' => 2, 'episodeFileId' => 502],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501,
            'seriesId' => 42,
            'sceneName' => 'Trusted.Anime.S01E01.1080p.WEB.CR',
            'releaseGroup' => 'SubsPlease',
            'size' => 1_500_000_000,
            'dateAdded' => '2026-01-01T00:00:00Z',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'mediaInfo' => ['subtitles' => 'English / Japanese'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response([
            'records' => [
                ['id' => 999, 'eventType' => 'grabbed', 'episodeId' => 101, 'downloadId' => 'ABC', 'sourceTitle' => 'Trusted.Anime.S01E01.1080p.WEB.CR'],
                ['id' => 1000, 'eventType' => 'downloadFolderImported', 'episodeId' => 101, 'downloadId' => 'ABC'],
            ],
        ]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1, episodeNumber: 1);

    expect($snapshot['ambiguous'])->toBeFalse()
        ->and($snapshot['service'])->toBe('sonarr')
        ->and($snapshot['scope'])->toBe('anime')
        ->and($snapshot['series_id'])->toBe(42)
        ->and($snapshot['episode_ids'])->toBe([101])
        ->and($snapshot['episode_file_ids'])->toBe([501])
        ->and($snapshot['subtitles'])->toBe(['eng', 'jpn'])
        ->and($snapshot['scene_name'])->toBe('Trusted.Anime.S01E01.1080p.WEB.CR')
        ->and($snapshot['installed_release'])->toBe('Trusted.Anime.S01E01.1080p.WEB.CR')
        ->and($snapshot['original_history_id'])->toBe(999)
        ->and($snapshot)->not->toHaveKey('path');
});

test('resolves the ordinary TV scope for non-anime series', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Ordinary Show', 'seriesType' => 'standard',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'Ordinary.Show.S01E01', 'mediaInfo' => ['subtitles' => 'English'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1, episodeNumber: 1);

    expect($snapshot['scope'])->toBe('tv')
        ->and($snapshot['subtitles'])->toBe(['eng'])
        ->and($snapshot['original_history_id'])->toBeNull();
});

test('inspects a Radarr movie file into a movie-scoped snapshot', function (): void {
    radarrInspectorConnection();

    Http::fake([
        'radarr.local:7878/api/v3/movie/88' => Http::response([
            'id' => 88, 'title' => 'A Movie', 'movieFileId' => 601,
        ]),
        'radarr.local:7878/api/v3/moviefile/601' => Http::response([
            'id' => 601,
            'movieId' => 88,
            'sceneName' => 'A.Movie.2026.1080p.BluRay',
            'releaseGroup' => 'GROUP',
            'quality' => ['quality' => ['name' => 'Bluray-1080p']],
            'mediaInfo' => ['subtitles' => 'English / Swedish'],
        ]),
        'radarr.local:7878/api/v3/history*' => Http::response([
            'records' => [
                ['id' => 777, 'eventType' => 'grabbed', 'movieId' => 88, 'downloadId' => 'XYZ'],
            ],
        ]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('radarr', 88);

    expect($snapshot['ambiguous'])->toBeFalse()
        ->and($snapshot['service'])->toBe('radarr')
        ->and($snapshot['scope'])->toBe('movie')
        ->and($snapshot['movie_id'])->toBe(88)
        ->and($snapshot['movie_file_ids'])->toBe([601])
        ->and($snapshot['subtitles'])->toBe(['eng', 'swe'])
        ->and($snapshot['original_history_id'])->toBe(777);
});

test('returns ambiguity data when a Sonarr selector matches multiple episodes', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
            ['id' => 102, 'seasonNumber' => 1, 'episodeNumber' => 2, 'episodeFileId' => 502],
        ]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1);

    expect($snapshot['ambiguous'])->toBeTrue()
        ->and($snapshot['reason'])->toBe('multiple_episodes')
        ->and($snapshot['choices'])->toHaveCount(2);
});

test('flags a shared multi-episode file as ambiguity with the affected episodes', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 500],
            ['id' => 102, 'seasonNumber' => 1, 'episodeNumber' => 2, 'episodeFileId' => 500],
        ]),
        'sonarr.local:8989/api/v3/episodefile/500' => Http::response([
            'id' => 500, 'sceneName' => 'Trusted.Anime.S01E01E02', 'mediaInfo' => ['subtitles' => 'English'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1, episodeNumber: 1);

    expect($snapshot['ambiguous'])->toBeTrue()
        ->and($snapshot['reason'])->toBe('shared_multi_episode_file')
        ->and($snapshot['affected_episodes'])->toHaveCount(2)
        ->and($snapshot['episode_ids'])->toBe([101, 102]);
});

test('returns ambiguity data when no episode matches the selector', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 9, episodeNumber: 9);

    expect($snapshot['ambiguous'])->toBeTrue()
        ->and($snapshot['reason'])->toBe('no_match');
});
