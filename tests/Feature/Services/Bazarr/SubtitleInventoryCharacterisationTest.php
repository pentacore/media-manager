<?php

declare(strict_types=1);

use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Services\Radarr\RadarrClient;
use App\Services\ServiceClientFactory;
use App\Settings\MediaReplacementSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

beforeEach(function (): void {
    Http::preventStrayRequests();

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'global_languages' => ['English'],
    ]);
});

/**
 * @return array{bazarr: ServiceConnection, sonarr: ServiceConnection, radarr: ServiceConnection}
 */
function subtitleCharacterisationConnections(): array
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

/**
 * The overview/library/missing/history reader. Task 9 re-points this helper.
 */
function subtitleCharacterisationReader(): SubtitleInventoryService
{
    return resolve(SubtitleInventoryService::class);
}

/**
 * The single-item inspector. Task 9 re-points this helper.
 */
function subtitleCharacterisationInspector(): SubtitleInventoryService
{
    return resolve(SubtitleInventoryService::class);
}

/**
 * The case-candidate projector. Task 9 re-points this helper.
 */
function subtitleCharacterisationCandidates(): SubtitleInventoryService
{
    return resolve(SubtitleInventoryService::class);
}

function subtitleCharacterisationTarget(string $role): object
{
    return match ($role) {
        'reader' => subtitleCharacterisationReader(),
        'inspector' => subtitleCharacterisationInspector(),
        'candidates' => subtitleCharacterisationCandidates(),
    };
}

test('inspect returns one sanitized movie and its bounded history from explicit identifiers', function (): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    Http::fake([
        'bazarr.test/api/movies/history*' => Http::response([
            'data' => [[
                'radarrId' => 801,
                'title' => 'Example Movie',
                'language' => ['code3' => 'swe'],
                'provider' => 'example-provider',
                'action' => 1,
                'score' => '88%',
                'subtitles_path' => '/media/movies/Example.sv.srt',
            ]],
            'total' => 1,
        ]),
        'bazarr.test/api/movies?*' => Http::response([
            'data' => [[
                'radarrId' => 801,
                'title' => 'Example Movie',
                'path' => '/media/movies/Example Movie/Example Movie.mkv',
                'subtitles' => [[
                    'code3' => 'eng',
                    'name' => 'English',
                    'path' => '/media/movies/Example Movie/Example Movie.en.srt',
                    'forced' => false,
                    'hi' => false,
                ]],
            ]],
            'total' => 1,
        ]),
    ]);

    $result = subtitleCharacterisationInspector()->inspect($bazarr, 'movie', 801);

    expect(array_keys($result))->toBe(['item', 'history', 'partial', 'errors'])
        ->and($result['partial'])->toBeFalse()
        ->and($result['errors'])->toBe([])
        ->and($result['item'])->toMatchArray([
            'media_type' => 'movie',
            'media_id' => 801,
            'scope' => 'movie',
            'title' => 'Example Movie',
            'required_languages' => ['eng'],
            'missing_languages' => [],
        ])
        ->and($result['item']['subtitle_tracks'])->toHaveCount(1)
        ->and($result['item']['subtitle_tracks'][0])->toMatchArray([
            'display_name' => 'Example Movie.en.srt',
            'language' => 'eng',
            'kind' => 'external',
        ])
        ->and($result['history'])->toHaveCount(1)
        ->and($result['history'][0])->toMatchArray([
            'media_type' => 'movie',
            'media_id' => 801,
            'language' => 'swe',
            'provider' => 'example-provider',
        ]);

    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('/media/');

    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/movies/history'
        && str_contains($request->url(), 'radarrid=801')
        && str_contains($request->url(), 'length=10'));
});

test('inspect refuses with the exact exception before or after its reads', function (
    string $connectionKey,
    ?string $deactivate,
    array $responses,
    string $mediaType,
    int $mediaId,
    string $exception,
    string $message,
): void {
    $connections = subtitleCharacterisationConnections();

    if ($deactivate !== null) {
        $connections[$deactivate]->update(['is_active' => false]);
    }

    if ($responses !== []) {
        Http::fake(array_map(static fn (array $body) => Http::response($body), $responses));
    }

    expect(fn (): array => subtitleCharacterisationInspector()->inspect($connections[$connectionKey], $mediaType, $mediaId))
        ->toThrow(new $exception($message));

    if ($responses === []) {
        Http::assertNothingSent();
    }
})->with([
    'unsupported media type' => ['bazarr', null, [], 'season', 1, InvalidArgumentException::class, 'Media type must be episode or movie.'],
    'non-positive media id' => ['bazarr', null, [], 'movie', 0, InvalidArgumentException::class, 'Media ID must be positive.'],
    'not a Bazarr connection' => ['sonarr', null, [], 'movie', 801, InvalidArgumentException::class, 'Subtitle inventory requires a Bazarr connection.'],
    'inactive mapped Sonarr' => ['bazarr', 'sonarr', [], 'episode', 701, ModelNotFoundException::class, 'The mapped Sonarr connection is missing or inactive.'],
    'inactive mapped Radarr' => ['bazarr', 'radarr', [], 'movie', 801, ModelNotFoundException::class, 'The mapped Radarr connection is missing or inactive.'],
    'episode not in Bazarr' => ['bazarr', null, ['bazarr.test/api/episodes?*' => ['data' => []]], 'episode', 701, ModelNotFoundException::class, 'The requested Bazarr episode was not found.'],
    'episode without a series id' => ['bazarr', null, ['bazarr.test/api/episodes?*' => ['data' => [['sonarrEpisodeId' => 701, 'title' => 'Orphan']]]], 'episode', 701, UnexpectedValueException::class, 'The requested Bazarr episode is missing its Sonarr series ID.'],
    'movie not in Bazarr' => ['bazarr', null, ['bazarr.test/api/movies?*' => ['data' => [], 'total' => 0]], 'movie', 801, ModelNotFoundException::class, 'The requested Bazarr movie was not found.'],
]);

test('inspect refuses an episode whose scope cannot be resolved with the exact message', function (): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response([
            'data' => [[
                'sonarrSeriesId' => 101,
                'sonarrEpisodeId' => 701,
                'title' => 'Orphan Episode',
                'subtitles' => [],
            ]],
        ]),
        'sonarr.test/api/v3/series/101' => Http::response([
            'id' => 101,
            'title' => 'Unmapped Series',
            'rootFolderPath' => '/unmapped',
            'seriesType' => 'standard',
        ]),
    ]);

    expect(fn (): array => subtitleCharacterisationInspector()->inspect($bazarr, 'episode', 701))
        ->toThrow(new UnexpectedValueException('The requested Bazarr episode could not be projected.'));
});

test('inspect refuses an episode when the mapped Sonarr connection cannot build a Sonarr client', function (): void {
    ['bazarr' => $bazarr, 'sonarr' => $sonarr, 'radarr' => $radarr] = subtitleCharacterisationConnections();

    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response([
            'data' => [[
                'sonarrSeriesId' => 101,
                'sonarrEpisodeId' => 701,
                'title' => 'Orphan Episode',
                'subtitles' => [],
            ]],
        ]),
    ]);

    $this->mock(ServiceClientFactory::class, function (MockInterface $mock) use ($bazarr, $sonarr, $radarr): void {
        $mock->shouldReceive('make')
            ->withArgs(fn (ServiceConnection $connection): bool => $connection->is($bazarr))
            ->andReturn(new BazarrClient($bazarr));
        $mock->shouldReceive('make')
            ->withArgs(fn (ServiceConnection $connection): bool => $connection->is($sonarr))
            ->andReturn(new RadarrClient($radarr));
    });

    expect(fn (): array => subtitleCharacterisationInspector()->inspect($bazarr, 'episode', 701))
        ->toThrow(new InvalidArgumentException('The mapped Sonarr connection is invalid.'));
});

test('every inventory entry point refuses a connection that is not Bazarr before any request', function (string $role, string $method, array $arguments): void {
    ['sonarr' => $sonarr] = subtitleCharacterisationConnections();

    expect(fn (): mixed => subtitleCharacterisationTarget($role)->{$method}($sonarr, ...$arguments))
        ->toThrow(new InvalidArgumentException('Subtitle inventory requires a Bazarr connection.'));

    Http::assertNothingSent();
})->with([
    'overview' => ['reader', 'overview', []],
    'library' => ['reader', 'library', [1, 25]],
    'missing' => ['reader', 'missing', [1, 25]],
    'history' => ['reader', 'history', [1, 25]],
    'case candidates' => ['candidates', 'caseCandidates', [1, 25]],
]);

test('missing and history refuse an unknown media type filter before any request', function (string $method): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    expect(fn (): array => subtitleCharacterisationReader()->{$method}($bazarr, 1, 25, ['media_type' => 'season']))
        ->toThrow(new InvalidArgumentException('Media type filter must be episode or movie.'));

    Http::assertNothingSent();
})->with(['missing', 'history']);

test('overview reports every unavailable feed in a fixed order with zeroed counts', function (): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    Http::fake([
        'bazarr.test/api/episodes/wanted*' => Http::response([], 500),
        'bazarr.test/api/movies/wanted*' => Http::response([], 500),
        'bazarr.test/api/system/health' => Http::response([], 500),
    ]);

    expect(subtitleCharacterisationReader()->overview($bazarr))->toBe([
        'missing' => ['episodes' => 0, 'movies' => 0, 'total' => 0],
        'health_issue_count' => 0,
        'partial' => true,
        'errors' => [
            'Episode subtitle counts are temporarily unavailable.',
            'Movie subtitle counts are temporarily unavailable.',
            'Bazarr health details are temporarily unavailable.',
        ],
    ]);
});

test('library, missing and history name both inactive mapped connections without any request', function (string $method): void {
    ['bazarr' => $bazarr, 'sonarr' => $sonarr, 'radarr' => $radarr] = subtitleCharacterisationConnections();
    $sonarr->update(['is_active' => false]);
    $radarr->update(['is_active' => false]);

    expect(subtitleCharacterisationReader()->{$method}($bazarr, 1, 25))->toBe([
        'data' => [],
        'page' => 1,
        'per_page' => 25,
        'total' => 0,
        'partial' => true,
        'errors' => [
            'The mapped Sonarr connection is missing or inactive.',
            'The mapped Radarr connection is missing or inactive.',
        ],
    ]);

    Http::assertNothingSent();
})->with(['library', 'missing', 'history']);

test('library reports an unavailable episode and movie inventory in a fixed order', function (): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([], 500),
        'bazarr.test/api/movies*' => Http::response([], 500),
    ]);

    expect(subtitleCharacterisationReader()->library($bazarr, 1, 25))->toBe([
        'data' => [],
        'page' => 1,
        'per_page' => 25,
        'total' => 0,
        'partial' => true,
        'errors' => [
            'Sonarr episode inventory is temporarily unavailable.',
            'Radarr movie inventory is temporarily unavailable.',
        ],
    ]);
});

test('missing reports its own unavailable Sonarr and Radarr wanted feeds in a fixed order', function (): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([], 500),
        'bazarr.test/api/movies/wanted*' => Http::response([], 500),
    ]);

    expect(subtitleCharacterisationReader()->missing($bazarr, 1, 25))->toBe([
        'data' => [],
        'page' => 1,
        'per_page' => 25,
        'total' => 0,
        'partial' => true,
        'errors' => [
            'Sonarr wanted subtitles are temporarily unavailable.',
            'Radarr wanted subtitles are temporarily unavailable.',
        ],
    ]);
});

test('history reports both unavailable feeds in a fixed order', function (): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    Http::fake([
        'bazarr.test/api/episodes/history*' => Http::response([], 500),
        'bazarr.test/api/movies/history*' => Http::response([], 500),
    ]);

    expect(subtitleCharacterisationReader()->history($bazarr, 1, 25))->toBe([
        'data' => [],
        'page' => 1,
        'per_page' => 25,
        'total' => 0,
        'partial' => true,
        'errors' => [
            'Sonarr subtitle history is temporarily unavailable.',
            'Radarr subtitle history is temporarily unavailable.',
        ],
    ]);
});

test('case candidates with no active mapped service return an empty complete page', function (): void {
    ['bazarr' => $bazarr, 'sonarr' => $sonarr, 'radarr' => $radarr] = subtitleCharacterisationConnections();
    $sonarr->update(['is_active' => false]);
    $radarr->update(['is_active' => false]);

    expect(subtitleCharacterisationCandidates()->caseCandidates($bazarr))->toBe([
        'data' => [],
        'page' => 1,
        'per_page' => 100,
        'total' => 0,
        'partial' => false,
        'errors' => [],
    ]);

    Http::assertNothingSent();
});

test('a partial discovery feed is rescanned on the next page instead of being memoised', function (): void {
    ['bazarr' => $bazarr] = subtitleCharacterisationConnections();

    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([]),
        'bazarr.test/api/movies*' => Http::response([], 500),
    ]);

    $movieListReads = fn (): int => Http::recorded()->filter(
        fn (array $record): bool => parse_url((string) $record[0]->url(), PHP_URL_PATH) === '/api/movies',
    )->count();

    $subtitleInventoryService = subtitleCharacterisationCandidates();
    $firstPage = $subtitleInventoryService->caseCandidates($bazarr, page: 1, perPage: 25);
    $readsAfterFirstPage = $movieListReads();

    $secondPage = $subtitleInventoryService->caseCandidates($bazarr, page: 2, perPage: 25);

    expect($firstPage['partial'])->toBeTrue()
        ->and($firstPage['errors'])->toBe(['Radarr movie inventory is temporarily unavailable.'])
        ->and($secondPage['errors'])->toBe(['Radarr movie inventory is temporarily unavailable.'])
        ->and($readsAfterFirstPage)->toBeGreaterThan(0)
        ->and($movieListReads())->toBeGreaterThan($readsAfterFirstPage);
});

test('a single-media projection returns null without any request for an unusable input', function (string $connectionKey, ?string $deactivate, string $mediaType, int $mediaId): void {
    $connections = subtitleCharacterisationConnections();

    if ($deactivate !== null) {
        $connections[$deactivate]->update(['is_active' => false]);
    }

    expect(subtitleCharacterisationCandidates()->caseCandidateForMedia($connections[$connectionKey], $mediaType, $mediaId))->toBeNull();

    Http::assertNothingSent();
})->with([
    'not a Bazarr connection' => ['sonarr', null, 'movie', 801],
    'inactive Bazarr' => ['bazarr', 'bazarr', 'movie', 801],
    'unsupported media type' => ['bazarr', null, 'season', 801],
    'non-positive media id' => ['bazarr', null, 'movie', 0],
    'inactive mapped Radarr' => ['bazarr', 'radarr', 'movie', 801],
    'inactive mapped Sonarr' => ['bazarr', 'sonarr', 'episode', 701],
]);

test('a single movie projects the same server-only identity as the bulk sweep', function (): void {
    ['bazarr' => $bazarr, 'radarr' => $radarr] = subtitleCharacterisationConnections();

    Http::fake([
        'bazarr.test/api/movies?*' => Http::response([
            'data' => [['radarrId' => 801, 'title' => 'Example Movie', 'subtitles' => []]],
            'total' => 1,
        ]),
        'radarr.test/api/v3/movie/801' => Http::response(['id' => 801, 'movieFileId' => 901]),
        'radarr.test/api/v3/moviefile/901' => Http::response([
            'id' => 901,
            'size' => 2000,
            'dateAdded' => '2026-07-16T08:00:00Z',
            'sceneName' => 'Movie.Release',
            'path' => '/private/movies/Example.mkv',
        ]),
    ]);

    $candidate = subtitleCharacterisationCandidates()->caseCandidateForMedia($bazarr, 'movie', 801);

    expect(array_keys($candidate))->toBe([
        'bazarr_connection_id',
        'service',
        'service_connection_id',
        'scope',
        'media_type',
        'target_ids',
        'display_name',
        'required_languages',
        'missing_languages',
        'current_subtitles',
        'monitored',
        'file_fingerprint',
        'requirements_fingerprint',
    ])
        ->and($candidate)->toMatchArray([
            'bazarr_connection_id' => $bazarr->id,
            'service' => 'radarr',
            'service_connection_id' => $radarr->id,
            'scope' => 'movie',
            'media_type' => 'movie',
            'target_ids' => ['radarr_id' => 801, 'movie_file_id' => 901],
            'display_name' => 'Example Movie',
            'required_languages' => ['eng'],
            'missing_languages' => ['eng'],
            'current_subtitles' => [],
            'monitored' => true,
        ])
        ->and($candidate['file_fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($candidate['requirements_fingerprint'])->toMatch('/^[a-f0-9]{64}$/');

    expect(json_encode($candidate, JSON_THROW_ON_ERROR))->not->toContain('/private/');
});

test('an existing episode case re-projects its live identity through the bulk plumbing', function (): void {
    ['bazarr' => $bazarr, 'sonarr' => $sonarr] = subtitleCharacterisationConnections();

    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'media_type' => 'episode',
        'target_ids' => ['series_id' => 101, 'episode_id' => 701, 'episode_file_id' => 501],
    ]);

    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response([
            'data' => [[
                'sonarrSeriesId' => 101,
                'sonarrEpisodeId' => 701,
                'title' => 'The Journey Begins',
                'subtitles' => [],
            ]],
        ]),
        'sonarr.test/api/v3/series/101' => Http::response([
            'id' => 101,
            'title' => 'Frieren',
            'rootFolderPath' => '/anime',
            'seriesType' => 'anime',
        ]),
        'sonarr.test/api/v3/episode?seriesId=101' => Http::response([
            ['id' => 701, 'seriesId' => 101, 'episodeFileId' => 501],
        ]),
        'sonarr.test/api/v3/episodefile/501' => Http::response([
            'id' => 501,
            'size' => 1000,
            'dateAdded' => '2026-07-16T08:00:00Z',
            'sceneName' => 'Episode.Release',
        ]),
    ]);

    $candidate = subtitleCharacterisationCandidates()->caseCandidateFor($subtitleCase);

    expect($candidate)->toMatchArray([
        'bazarr_connection_id' => $bazarr->id,
        'service' => 'sonarr',
        'service_connection_id' => $sonarr->id,
        'scope' => 'anime',
        'media_type' => 'episode',
        'target_ids' => [
            'series_id' => 101,
            'episode_id' => 701,
            'episode_ids' => [701],
            'episode_file_id' => 501,
        ],
        'display_name' => 'Frieren — The Journey Begins',
        'required_languages' => ['eng'],
        'missing_languages' => ['eng'],
    ]);
});

test('a case without a positive target id projects to null without any request', function (): void {
    ['bazarr' => $bazarr, 'sonarr' => $sonarr] = subtitleCharacterisationConnections();

    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'media_type' => 'episode',
        'target_ids' => ['series_id' => 101],
    ]);

    expect(subtitleCharacterisationCandidates()->caseCandidateFor($subtitleCase))->toBeNull();

    Http::assertNothingSent();
});
