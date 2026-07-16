<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\MediaReplacement\MediaFileInspector;
use App\Settings\MediaReplacementSettings;
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

test('resolves standard-numbered anime from its configured Sonarr root folder', function (): void {
    $serviceConnection = sonarrInspectorConnection();
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'sonarr_root_folders' => [[
            'service_connection_id' => $serviceConnection->id,
            'root_folder_id' => 2,
            'path' => '/anime',
            'scope' => 'anime',
        ]],
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42,
            'title' => 'Standard Numbered Anime',
            'seriesType' => 'standard',
            'rootFolderPath' => '/anime',
            'path' => '/anime/Standard Numbered Anime',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'Anime.S01E01', 'mediaInfo' => ['subtitles' => 'English'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1, episodeNumber: 1);

    expect($snapshot['ambiguous'])->toBeFalse()
        ->and($snapshot['scope'])->toBe('anime')
        ->and($snapshot)->not->toHaveKey('path');
});

test('requires an explicit content type for an unconfigured Sonarr root folder', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42,
            'title' => 'Unclassified Show',
            'seriesType' => 'standard',
            'rootFolderPath' => '/unclassified',
            'path' => '/unclassified/Unclassified Show',
        ]),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1, episodeNumber: 1);

    expect($snapshot)->toBe([
        'ambiguous' => true,
        'reason' => 'unconfigured_root_scope',
        'service' => 'sonarr',
    ]);
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

test('inspects a season 0 special (season number zero is valid)', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 900, 'seasonNumber' => 0, 'episodeNumber' => 1, 'episodeFileId' => 555],
        ]),
        'sonarr.local:8989/api/v3/episodefile/555' => Http::response([
            'id' => 555, 'sceneName' => 'Trusted.Anime.S00E01.OVA', 'mediaInfo' => ['subtitles' => 'English'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 0, episodeNumber: 1);

    expect($snapshot['ambiguous'])->toBeFalse()
        ->and($snapshot['season_number'])->toBe(0)
        ->and($snapshot['episode_file_ids'])->toBe([555]);
});

test('blocklists the grab correlated to the installed file, not a global-unique grab', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'A', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'current', 'mediaInfo' => ['subtitles' => 'Japanese']],
        ),
        // Two grabs over the item's life (initial + upgrade); import ties the current file (501) to downloadId DL2.
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [
            ['id' => 10, 'eventType' => 'grabbed', 'episodeId' => 101, 'downloadId' => 'DL1', 'date' => '2026-01-01T00:00:00Z'],
            ['id' => 11, 'eventType' => 'grabbed', 'episodeId' => 101, 'downloadId' => 'DL2', 'date' => '2026-02-01T00:00:00Z'],
            ['id' => 12, 'eventType' => 'downloadFolderImported', 'episodeId' => 101, 'downloadId' => 'DL2', 'episodeFileId' => 501, 'date' => '2026-02-01T01:00:00Z'],
        ]]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1, episodeNumber: 1);

    expect($snapshot['original_history_id'])->toBe(11);
});

test('does not blocklist an uncorrelated grab when multiple grabs exist', function (): void {
    sonarrInspectorConnection();

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'A', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'current', 'mediaInfo' => ['subtitles' => 'Japanese']],
        ),
        // Two grabs, and the import does NOT correlate to the installed file
        // (its episodeFileId differs), so neither grab is positively tied to the
        // current file and there is no unique grab → must not guess.
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [
            ['id' => 10, 'eventType' => 'grabbed', 'episodeId' => 101, 'downloadId' => 'DL1', 'date' => '2026-01-01T00:00:00Z'],
            ['id' => 11, 'eventType' => 'grabbed', 'episodeId' => 101, 'downloadId' => 'DL2', 'date' => '2026-02-01T00:00:00Z'],
            ['id' => 12, 'eventType' => 'downloadFolderImported', 'episodeId' => 101, 'downloadId' => 'DL3', 'episodeFileId' => 999, 'date' => '2026-02-01T01:00:00Z'],
        ]]),
    ]);

    $snapshot = resolve(MediaFileInspector::class)->inspect('sonarr', 42, seasonNumber: 1, episodeNumber: 1);

    expect($snapshot['original_history_id'])->toBeNull();
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
