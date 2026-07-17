<?php

declare(strict_types=1);

use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array{bazarr: ServiceConnection, sonarr: ServiceConnection, radarr: ServiceConnection}
 */
function subtitleInventoryConnections(): array
{
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.test',
        'api_key' => 'sonarr-secret',
        'settings' => [
            'sonarr_root_folders' => [
                ['root_folder_id' => 1, 'path' => '/anime', 'scope' => 'anime'],
                ['root_folder_id' => 2, 'path' => '/tv', 'scope' => 'tv'],
            ],
        ],
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.test',
        'api_key' => 'radarr-secret',
    ]);

    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    BazarrServiceLink::factory()->radarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
    ]);

    return ['bazarr' => $bazarr, 'sonarr' => $sonarr, 'radarr' => $radarr];
}

test('library projects sanitized anime television and movie subtitle requirements', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'global_languages' => ['English'],
    ]);

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([
            ['id' => 101, 'title' => 'Frieren', 'rootFolderPath' => '/anime', 'path' => '/anime/Frieren', 'seriesType' => 'anime'],
            ['id' => 202, 'title' => 'Example TV', 'rootFolderPath' => '/tv', 'path' => '/tv/Example TV', 'seriesType' => 'standard'],
        ]),
        'bazarr.test/api/episodes*' => Http::response([
            'data' => [
                [
                    'sonarrSeriesId' => 101,
                    'sonarrEpisodeId' => 701,
                    'season' => 1,
                    'episode' => 1,
                    'title' => 'The Journey Begins',
                    'path' => '/media/anime/Frieren S01E01.mkv',
                    'missing_subtitles' => [['code3' => 'swe']],
                    'subtitles' => [[
                        'code3' => 'jpn',
                        'name' => 'Japanese',
                        'path' => null,
                        'embedded_track_id' => 2,
                        'forced' => false,
                        'hi' => false,
                    ]],
                ],
                [
                    'sonarrSeriesId' => 202,
                    'sonarrEpisodeId' => 702,
                    'season' => 2,
                    'episode' => 3,
                    'title' => 'A TV Episode',
                    'path' => '/media/tv/Example TV S02E03.mkv',
                    'missing_subtitles' => [],
                    'subtitles' => [[
                        'code3' => 'eng',
                        'name' => 'English',
                        'path' => '/media/tv/Example TV S02E03.en.srt',
                        'embedded_track_id' => null,
                        'forced' => false,
                        'hi' => false,
                    ]],
                ],
            ],
        ]),
        'bazarr.test/api/movies*' => Http::response([
            'data' => [[
                'radarrId' => 801,
                'title' => 'Example Movie',
                'path' => '/media/movies/Example Movie/Example Movie.mkv',
                'missing_subtitles' => [['code3' => 'swe']],
                'subtitles' => [],
            ]],
            'total' => 1,
        ]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->library($bazarr, page: 1, perPage: 25);

    expect($result)
        ->toMatchArray([
            'page' => 1,
            'per_page' => 25,
            'total' => 3,
            'partial' => false,
        ])
        ->and($result['data'])->toHaveCount(3)
        ->and($result['data'][0])->toMatchArray([
            'media_type' => 'episode',
            'scope' => 'anime',
            'title' => 'Frieren — The Journey Begins',
            'required_languages' => ['eng'],
            'missing_languages' => ['eng'],
        ])
        ->and($result['data'][0]['subtitle_tracks'])->toHaveCount(1)
        ->and($result['data'][0]['subtitle_tracks'][0])->toMatchArray([
            'language' => 'jpn',
            'kind' => 'embedded',
            'forced' => false,
            'hearing_impaired' => false,
        ])
        ->and($result['data'][1])->toMatchArray([
            'media_type' => 'episode',
            'scope' => 'tv',
            'required_languages' => ['eng'],
            'missing_languages' => [],
        ])
        ->and($result['data'][2])->toMatchArray([
            'media_type' => 'movie',
            'scope' => 'movie',
            'required_languages' => ['eng'],
            'missing_languages' => ['eng'],
        ]);

    $json = json_encode($result, JSON_THROW_ON_ERROR);

    expect($json)
        ->not->toContain('/media/')
        ->not->toContain('subtitles_path')
        ->not->toContain('video_path');

    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/episodes'
        && str_contains($request->url(), 'seriesid%5B%5D=101')
        && str_contains($request->url(), 'seriesid%5B%5D=202'));
});

test('episode library batches positive Sonarr series identifiers without an unfiltered Bazarr request', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();
    $series = array_map(
        static fn (int $id): array => [
            'id' => $id,
            'title' => 'Series '.$id,
            'seriesType' => 'anime',
        ],
        range(1, 51),
    );

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response($series),
        'bazarr.test/api/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/movies*' => Http::response(['data' => [], 'total' => 0]),
    ]);

    resolve(SubtitleInventoryService::class)->library($bazarr, page: 1, perPage: 25);

    $episodeRequests = collect(Http::recorded())
        ->map(static fn (array $record): Request => $record[0])
        ->filter(static fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/episodes')
        ->values();

    expect($episodeRequests)->toHaveCount(2);

    $batchSizes = $episodeRequests
        ->map(function (Request $request): int {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return count($query['seriesid'] ?? []);
        })
        ->all();

    expect($batchSizes)->toBe([50, 1]);

    foreach ($episodeRequests as $episodeRequest) {
        parse_str((string) parse_url($episodeRequest->url(), PHP_URL_QUERY), $query);

        expect($query['seriesid'] ?? [])->not->toBeEmpty();
    }
});

test('inactive mapped connections return a controlled partial inventory result', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr, 'sonarr' => $sonarr] = subtitleInventoryConnections();
    $sonarr->update(['is_active' => false]);

    Http::fake([
        'bazarr.test/api/movies*' => Http::response(['data' => [], 'total' => 0]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->library($bazarr, page: 1, perPage: 25);

    expect($result)
        ->toMatchArray([
            'data' => [],
            'total' => 0,
            'partial' => true,
        ])
        ->and($result['errors'])->toContain('The mapped Sonarr connection is missing or inactive.');

    Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/episodes');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.test'));
});

test('missing inventory uses wanted feeds and MediaManager requirements', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'global_languages' => ['English'],
    ]);

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([
            ['id' => 101, 'title' => 'Frieren', 'rootFolderPath' => '/anime', 'seriesType' => 'anime'],
        ]),
        'bazarr.test/api/episodes/wanted*' => Http::response([
            'data' => [[
                'sonarrSeriesId' => 101,
                'sonarrEpisodeId' => 701,
                'episodeTitle' => 'The Journey Begins',
                'missing_subtitles' => [['code3' => 'swe']],
            ]],
            'total' => 1,
        ]),
        'bazarr.test/api/movies/wanted*' => Http::response([
            'data' => [[
                'radarrId' => 801,
                'title' => 'Example Movie',
                'missing_subtitles' => [['code3' => 'swe']],
            ]],
            'total' => 1,
        ]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->missing($bazarr, page: 1, perPage: 25);

    expect($result)
        ->toMatchArray([
            'page' => 1,
            'per_page' => 25,
            'total' => 2,
            'partial' => false,
        ])
        ->and($result['data'])->toHaveCount(2)
        ->and($result['data'][0])->toMatchArray([
            'media_type' => 'episode',
            'scope' => 'anime',
            'required_languages' => ['eng'],
            'missing_languages' => ['eng'],
        ])
        ->and($result['data'][1])->toMatchArray([
            'media_type' => 'movie',
            'scope' => 'movie',
            'required_languages' => ['eng'],
            'missing_languages' => ['eng'],
        ]);

    Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/episodes');
});

test('history projection omits upstream paths and subtitle identifiers', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    Http::fake([
        'bazarr.test/api/episodes/history*' => Http::response([
            'data' => [[
                'sonarrEpisodeId' => 701,
                'seriesTitle' => 'Frieren',
                'episodeTitle' => 'The Journey Begins',
                'language' => ['code3' => 'eng'],
                'provider' => 'example-provider',
                'action' => 1,
                'score' => '95%',
                'parsed_timestamp' => '07/17/26 10:00:00',
                'subtitles_path' => '/media/anime/Frieren.en.srt',
                'subs_id' => 'private-upstream-id',
            ]],
            'total' => 1,
        ]),
        'bazarr.test/api/movies/history*' => Http::response([
            'data' => [[
                'radarrId' => 801,
                'title' => 'Example Movie',
                'language' => ['code3' => 'swe'],
                'provider' => 'other-provider',
                'action' => 1,
                'score' => '91%',
                'parsed_timestamp' => '07/17/26 09:00:00',
                'subtitles_path' => '/media/movies/Example.sv.srt',
                'subs_id' => 'private-movie-id',
            ]],
            'total' => 1,
        ]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->history($bazarr, page: 1, perPage: 25);

    expect($result['data'])->toHaveCount(2)
        ->and($result['data'][0])->toMatchArray([
            'media_type' => 'episode',
            'media_id' => 701,
            'language' => 'eng',
            'provider' => 'example-provider',
        ])
        ->and($result['data'][1])->toMatchArray([
            'media_type' => 'movie',
            'media_id' => 801,
            'language' => 'swe',
            'provider' => 'other-provider',
        ]);

    expect(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain('/media/')
        ->not->toContain('subs_id')
        ->not->toContain('private-upstream-id');
});

test('overview exposes bounded missing counts without raw upstream payloads', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    Http::fake([
        'bazarr.test/api/episodes/wanted*' => Http::response(['data' => [], 'total' => 12]),
        'bazarr.test/api/movies/wanted*' => Http::response(['data' => [], 'total' => 8]),
        'bazarr.test/api/system/health' => Http::response([
            'data' => [
                ['object' => 'System', 'issue' => 'Provider unavailable', 'path' => '/private/provider/path'],
            ],
        ]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->overview($bazarr);

    expect($result)->toBe([
        'missing' => [
            'episodes' => 12,
            'movies' => 8,
            'total' => 20,
        ],
        'health_issue_count' => 1,
        'partial' => false,
        'errors' => [],
    ]);

    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('/private/');
});

test('inspect returns one sanitized episode and bounded history from explicit identifiers', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response([
            'data' => [[
                'sonarrSeriesId' => 101,
                'sonarrEpisodeId' => 701,
                'title' => 'The Journey Begins',
                'path' => '/media/anime/Frieren S01E01.mkv',
                'subtitles' => [],
            ]],
        ]),
        'sonarr.test/api/v3/series/101' => Http::response([
            'id' => 101,
            'title' => 'Frieren',
            'rootFolderPath' => '/anime',
            'seriesType' => 'anime',
        ]),
        'bazarr.test/api/episodes/history*' => Http::response([
            'data' => [[
                'sonarrEpisodeId' => 701,
                'seriesTitle' => 'Frieren',
                'episodeTitle' => 'The Journey Begins',
                'language' => ['code3' => 'jpn'],
                'provider' => 'example-provider',
                'action' => 1,
                'score' => '90%',
                'subtitles_path' => '/media/anime/Frieren.ja.srt',
            ]],
            'total' => 1,
        ]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->inspect($bazarr, 'episode', 701);

    expect($result['item'])->toMatchArray([
        'media_type' => 'episode',
        'media_id' => 701,
        'series_id' => 101,
        'scope' => 'anime',
        'required_languages' => ['eng'],
        'missing_languages' => ['eng'],
    ])
        ->and($result['history'])->toHaveCount(1)
        ->and($result['history'][0])->toMatchArray([
            'media_type' => 'episode',
            'media_id' => 701,
            'language' => 'jpn',
        ]);

    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('/media/');

    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/episodes'
        && str_contains($request->url(), 'episodeid%5B%5D=701'));
});

test('one failed wanted feed yields a partial result without discarding the other service', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([
            ['id' => 101, 'title' => 'Frieren', 'seriesType' => 'anime'],
        ]),
        'bazarr.test/api/episodes/wanted*' => Http::sequence()
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        'bazarr.test/api/movies/wanted*' => Http::response([
            'data' => [[
                'radarrId' => 801,
                'title' => 'Example Movie',
                'missing_subtitles' => [],
            ]],
            'total' => 1,
        ]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->missing($bazarr, page: 1, perPage: 25);

    expect($result)
        ->toMatchArray([
            'total' => 1,
            'partial' => true,
        ])
        ->and($result['data'])->toHaveCount(1)
        ->and($result['data'][0]['media_type'])->toBe('movie')
        ->and($result['errors'])->toContain('Sonarr wanted subtitles are temporarily unavailable.');
});

test('missing media type filters keep totals and upstream reads scoped to one service', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([
            ['id' => 101, 'title' => 'Frieren', 'seriesType' => 'anime'],
        ]),
        'bazarr.test/api/episodes/wanted*' => Http::response([
            'data' => [[
                'sonarrSeriesId' => 101,
                'sonarrEpisodeId' => 701,
                'episodeTitle' => 'The Journey Begins',
                'missing_subtitles' => [],
            ]],
            'total' => 7,
        ]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->missing(
        $bazarr,
        page: 1,
        perPage: 25,
        filters: ['media_type' => 'episode'],
    );

    expect($result['total'])->toBe(7)
        ->and($result['data'])->toHaveCount(1)
        ->and($result['data'][0]['media_type'])->toBe('episode');

    Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/movies/wanted');
});

test('library tolerates malformed tracks and preserves shared episode rows without exposing their path', function (): void {
    Http::preventStrayRequests();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([
            ['id' => 101, 'title' => 'Shared Show', 'seriesType' => 'anime'],
        ]),
        'bazarr.test/api/episodes*' => Http::response([
            'data' => [
                [
                    'sonarrSeriesId' => 101,
                    'sonarrEpisodeId' => 701,
                    'title' => 'Part One',
                    'path' => '/media/shared/file.mkv',
                    'subtitles' => ['invalid', [], ['path' => '/media/shared/no-language.srt']],
                ],
                [
                    'sonarrSeriesId' => 101,
                    'sonarrEpisodeId' => 702,
                    'title' => 'Part Two',
                    'path' => '/media/shared/file.mkv',
                    'subtitles' => null,
                ],
            ],
        ]),
        'bazarr.test/api/movies*' => Http::response(['data' => [], 'total' => 0]),
    ]);

    $result = resolve(SubtitleInventoryService::class)->library(
        $bazarr,
        page: 1,
        perPage: 25,
        filters: ['media_type' => 'episode', 'scope' => 'anime', 'missing_only' => true],
    );

    expect($result['data'])->toHaveCount(2)
        ->and($result['data'][0]['subtitle_tracks'])->toBe([])
        ->and($result['data'][1]['subtitle_tracks'])->toBe([]);

    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('/media/shared/');
});

test('inventory pagination rejects invalid bounds before upstream requests', function (string $method, int $page, int $perPage): void {
    Http::preventStrayRequests();
    Http::fake();

    ['bazarr' => $bazarr] = subtitleInventoryConnections();

    expect(fn (): array => resolve(SubtitleInventoryService::class)->{$method}($bazarr, $page, $perPage))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'library page zero' => ['library', 0, 25],
    'missing per page zero' => ['missing', 1, 0],
    'history per page too large' => ['history', 1, 101],
]);
