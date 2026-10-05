<?php

declare(strict_types=1);

use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleCaseCandidates;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'global_languages' => ['English'],
    ]);
});

function subtitleCaseCandidatesBazarr(): ServiceConnection
{
    $bazarr = ServiceConnection::factory()->bazarr()->create(['url' => 'http://bazarr.test']);
    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.test',
        'settings' => [
            'sonarr_root_folders' => [
                ['root_folder_id' => 1, 'path' => '/anime', 'scope' => 'anime'],
            ],
        ],
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.test']);

    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    BazarrServiceLink::factory()->radarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
    ]);

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([
            ['id' => 101, 'title' => 'Frieren', 'rootFolderPath' => '/anime', 'seriesType' => 'anime'],
        ]),
        'bazarr.test/api/episodes*' => Http::response([
            'data' => [
                ['sonarrSeriesId' => 101, 'sonarrEpisodeId' => 701, 'title' => 'One', 'subtitles' => []],
            ],
        ]),
        'bazarr.test/api/movies*' => Http::response([
            'data' => [['radarrId' => 801, 'title' => 'Movie One', 'subtitles' => []]],
            'total' => 1,
        ]),
        'sonarr.test/api/v3/episode?seriesId=101' => Http::response([
            ['id' => 701, 'seriesId' => 101, 'episodeFileId' => 501],
        ]),
        'sonarr.test/api/v3/episodefile/*' => Http::response(['size' => 1000, 'dateAdded' => '2026-07-16T08:00:00Z', 'sceneName' => 'Episode.Release']),
        'radarr.test/api/v3/movie/801' => Http::response(['id' => 801, 'movieFileId' => 901]),
        'radarr.test/api/v3/moviefile/*' => Http::response(['size' => 2000, 'dateAdded' => '2026-07-16T08:00:00Z', 'sceneName' => 'Movie.Release']),
    ]);

    return $bazarr;
}

function subtitleCaseCandidatesCatalogReads(): int
{
    return Http::recorded()->filter(function (array $record): bool {
        $path = parse_url((string) $record[0]->url(), PHP_URL_PATH);

        return in_array($path, ['/api/movies', '/api/v3/series'], true);
    })->count();
}

test('the container hands out a fresh case-candidate projector on every resolve', function (): void {
    expect(resolve(SubtitleCaseCandidates::class))->not->toBe(resolve(SubtitleCaseCandidates::class));
});

test('one projector scans the catalog once across pages and a second projector scans it again', function (): void {
    $serviceConnection = subtitleCaseCandidatesBazarr();

    $subtitleCaseCandidates = resolve(SubtitleCaseCandidates::class);
    $subtitleCaseCandidates->caseCandidates($serviceConnection, page: 1, perPage: 1);
    Cache::flush();
    $subtitleCaseCandidates->caseCandidates($serviceConnection, page: 2, perPage: 1);

    expect(subtitleCaseCandidatesCatalogReads())->toBe(2);

    // A later reconciliation cycle (a new job, possibly in the same long-lived
    // worker) resolves a new projector and must not inherit the first snapshot.
    Cache::flush();
    resolve(SubtitleCaseCandidates::class)->caseCandidates($serviceConnection, page: 1, perPage: 1);

    expect(subtitleCaseCandidatesCatalogReads())->toBe(4);
});
