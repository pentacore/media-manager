<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Arr\ReleaseGrabFailed;
use App\Services\Arr\SearchCommandFailed;
use App\Services\MediaReplacement\ReplacementInFlight;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);
});

test('deleteSeries sends DELETE to sonarr with deleteFiles flag', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/series/42*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => ['sonarr_series_id' => 42, 'delete_files' => true],
    ]);

    $result = (new SonarrActions)->execute($request);

    expect($result)->toMatchArray(['sonarr_series_id' => 42, 'delete_files' => true]);

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/series/42')
        && str_contains((string) $r->url(), 'deleteFiles=true'));
});

test('deleteSeries defaults delete_files to false', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/series/7*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => ['sonarr_series_id' => 7],
    ]);

    (new SonarrActions)->execute($request);

    Http::assertSent(fn ($r): bool => str_contains((string) $r->url(), 'deleteFiles=false'));
});

test('deleteSeries throws when sonarr_series_id is missing', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => [],
    ]);

    expect(fn (): array => (new SonarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('execute throws for unknown type', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'some_unknown_type',
        'payload' => [],
    ]);

    expect(fn (): array => (new SonarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('add_series executor calls SonarrClient::searchSeries then addSeries', function (): void {
    // Fake order matters: the lookup pattern must be matched before the
    // bare /series pattern, otherwise the more general key wins for
    // /series/lookup?... requests.
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Demo Show', 'tvdbId' => 999001, 'year' => 2024],
        ]),
        'sonarr.local:8989/api/v3/series' => Http::sequence()
            ->push([
                'id' => 123,
                'title' => 'Demo Show',
                'tvdbId' => 999001,
            ]),
    ]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'add_series',
        'target_service' => 'sonarr',
        'payload' => [
            'tvdb_id' => 999001,
            'quality_profile_id' => 1,
            'root_folder_path' => '/tv',
            'monitored' => true,
            'season_folder' => true,
        ],
    ]);

    $result = (new SonarrActions)->execute($actionRequest);

    expect($result['sonarr_series_id'])->toBe(123);
    expect($result['title'])->toBe('Demo Show');
    expect($result['tvdb_id'])->toBe(999001);
});

test('monitor_series executor toggles monitored via getSeriesById + updateSeries', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Demo', 'monitored' => true]),
    ]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'monitor_series',
        'target_service' => 'sonarr',
        'payload' => ['series_id' => 42, 'monitored' => false],
    ]);

    $result = (new SonarrActions)->execute($actionRequest);

    expect($result['sonarr_series_id'])->toBe(42);
    expect($result['monitored'])->toBeFalse();

    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_contains((string) $r->url(), '/api/v3/series/42')
        && $r->data()['monitored'] === false);
});

test('set_series_quality_profile executor mutates qualityProfileId via getSeriesById + updateSeries', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Demo', 'qualityProfileId' => 1]),
    ]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'set_series_quality_profile',
        'target_service' => 'sonarr',
        'payload' => ['series_id' => 42, 'quality_profile_id' => 7],
    ]);

    $result = (new SonarrActions)->execute($actionRequest);

    expect($result['quality_profile_id'])->toBe(7);

    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_contains((string) $r->url(), '/api/v3/series/42')
        && $r->data()['qualityProfileId'] === 7);
});

test('an approved delete against a deactivated pinned connection sends nothing', function (): void {
    $pinned = ServiceConnection::factory()->sonarr()->inactive()->create(['url' => 'http://sonarr-4k.local:8989']);
    Http::fake();

    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => ['sonarr_series_id' => 42, 'delete_files' => true, 'service_connection_id' => $pinned->id],
    ]);

    expect(fn (): array => (new SonarrActions)->execute($request))->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
});

function sonarrActionsConnectionId(): int
{
    return ServiceConnection::query()->where('type', 'sonarr')->firstOrFail()->id;
}

function sonarrActionsQueuedReplacement(int $seriesId): void
{
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => [
            'service' => 'sonarr',
            'service_connection_id' => sonarrActionsConnectionId(),
            'series_id' => $seriesId,
            'season_number' => 1,
            'episode_numbers' => [1],
        ]],
    ]);
}

test('monitor_episodes sets monitoring on exactly the given episodes', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 202)]);

    $result = (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'monitor_episodes',
        'payload' => ['series_id' => 7, 'episode_ids' => [70, 71, 70], 'monitored' => false, 'season_number' => 1, 'service_connection_id' => sonarrActionsConnectionId()],
    ]));

    expect($result)->toBe(['sonarr_series_id' => 7, 'episode_ids' => [70, 71], 'monitored' => false]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/api/v3/episode/monitor')
        && $request['episodeIds'] === [70, 71]
        && $request['monitored'] === false);
});

test('monitor changes are refused while a replacement for the series is in flight', function (string $type, array $payload): void {
    sonarrActionsQueuedReplacement(7);

    expect(fn (): array => resolve(SonarrActions::class)->execute(ActionRequest::factory()->create([
        'type' => $type,
        'payload' => [...$payload, 'service_connection_id' => sonarrActionsConnectionId()],
    ])))
        ->toThrow(ReplacementInFlight::class);

    Http::assertNothingSent();
})->with([
    'episodes' => ['monitor_episodes', ['series_id' => 7, 'episode_ids' => [70], 'monitored' => false]],
    'series' => ['monitor_series', ['series_id' => 7, 'monitored' => false]],
]);

test('the pinned-connection executors refuse to run without a pinned connection', function (string $type, array $payload): void {
    expect(fn (): array => resolve(SonarrActions::class)->execute(ActionRequest::factory()->create(['type' => $type, 'payload' => $payload])))
        ->toThrow(InvalidArgumentException::class, 'not pinned to a Sonarr connection');

    Http::assertNothingSent();
})->with([
    'monitor_episodes' => ['monitor_episodes', ['series_id' => 7, 'episode_ids' => [70], 'monitored' => false]],
    'search_media' => ['search_media', ['service' => 'sonarr', 'command' => 'series_search', 'series_id' => 7]],
    'grab_release' => ['grab_release', ['service' => 'sonarr', 'series_id' => 7, 'guid' => 'g-1', 'indexer_id' => 3, 'release' => ['title' => 'x']]],
]);

test('the pinned-connection executors refuse to run against a mismatched connection type', function (string $type, array $payload): void {
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    expect(fn (): array => resolve(SonarrActions::class)->execute(ActionRequest::factory()->create([
        'type' => $type,
        'payload' => [...$payload, 'service_connection_id' => $radarr->id],
    ])))->toThrow(InvalidArgumentException::class, 'not pinned to a Sonarr connection');

    Http::assertNothingSent();
})->with([
    'monitor_episodes' => ['monitor_episodes', ['series_id' => 7, 'episode_ids' => [70], 'monitored' => false]],
    'search_media' => ['search_media', ['service' => 'sonarr', 'command' => 'series_search', 'series_id' => 7]],
    'grab_release' => ['grab_release', ['service' => 'sonarr', 'series_id' => 7, 'guid' => 'g-1', 'indexer_id' => 3, 'release' => ['title' => 'x']]],
]);

test('search_media runs the matching Sonarr command', function (string $command, array $payload, array $body): void {
    Http::fake(['sonarr.local:8989/api/v3/command' => Http::response(['id' => 501, 'name' => $body['name']], 201)]);

    $result = (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'search_media',
        'payload' => ['service' => 'sonarr', 'command' => $command, ...$payload, 'service_connection_id' => sonarrActionsConnectionId()],
    ]));

    expect($result)->toBe(['command' => $command, 'arr_command_id' => 501]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === $body);
})->with([
    'series' => ['series_search', ['series_id' => 7], ['name' => 'SeriesSearch', 'seriesId' => 7]],
    'season' => ['season_search', ['series_id' => 7, 'season_number' => 2], ['name' => 'SeasonSearch', 'seriesId' => 7, 'seasonNumber' => 2]],
    'episode' => ['episode_search', ['series_id' => 7, 'episode_ids' => [70]], ['name' => 'EpisodeSearch', 'episodeIds' => [70]]],
    'all missing' => ['missing_episode_search', [], ['name' => 'MissingEpisodeSearch', 'monitored' => true]],
    'all cutoff unmet' => ['cutoff_unmet_episode_search', [], ['name' => 'CutoffUnmetEpisodeSearch', 'monitored' => true]],
]);

test('search_media refuses a Radarr command', function (): void {
    expect(fn (): array => (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'search_media',
        'payload' => ['service' => 'sonarr', 'command' => 'movies_search', 'movie_ids' => [1], 'service_connection_id' => sonarrActionsConnectionId()],
    ])))->toThrow(InvalidArgumentException::class);
});

test('a library-wide search that never gets a confirmed response fails without HTTP-level retry', function (): void {
    $attempts = 0;
    Http::fake(['sonarr.local:8989/api/v3/command' => function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('reset');
    }]);

    expect(fn (): array => (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'search_media',
        'payload' => ['service' => 'sonarr', 'command' => 'missing_episode_search', 'service_connection_id' => sonarrActionsConnectionId()],
    ])))->toThrow(SearchCommandFailed::class, 'did not confirm this library-wide search');

    // withRetry: false means exactly one attempt — the generic 3x HTTP retry
    // must not have fired for a library-wide search.
    expect($attempts)->toBe(1);
});

test('a targeted search rethrows a connection loss so the queue can retry it', function (): void {
    $attempts = 0;
    Http::fake(['sonarr.local:8989/api/v3/command' => function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('reset');
    }]);

    expect(fn (): array => (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'search_media',
        'payload' => ['service' => 'sonarr', 'command' => 'series_search', 'series_id' => 7, 'service_connection_id' => sonarrActionsConnectionId()],
    ])))->toThrow(ConnectionException::class);

    expect($attempts)->toBe(1);
});

test('grab_release posts only the guid and indexer id', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response(['guid' => 'g-1'], 200)]);

    $result = (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'sonarr', 'series_id' => 7, 'guid' => 'g-1', 'indexer_id' => 3, 'release' => ['title' => 'Show.S01E01.1080p'], 'service_connection_id' => sonarrActionsConnectionId()],
    ]));

    expect($result)->toBe(['guid' => 'g-1', 'indexer_id' => 3, 'title' => 'Show.S01E01.1080p']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['guid' => 'g-1', 'indexerId' => 3]);
});

test('grab_release reports an expired release as a search-again failure', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response(['message' => "Couldn't find requested release in cache, try searching again"], 404)]);

    expect(fn (): array => (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'sonarr', 'series_id' => 7, 'guid' => 'g-1', 'indexer_id' => 3, 'release' => ['title' => 'x'], 'service_connection_id' => sonarrActionsConnectionId()],
    ])))->toThrow(ReleaseGrabFailed::class, 'Run the interactive search again');
});

test('grab_release completes even when busting the cache afterward fails', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response(['guid' => 'g-1'], 200)]);
    config()->set('mediamanager.cache.store', 'this-store-does-not-exist');

    $result = (new SonarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'sonarr', 'series_id' => 7, 'guid' => 'g-1', 'indexer_id' => 3, 'release' => ['title' => 'x'], 'service_connection_id' => sonarrActionsConnectionId()],
    ]));

    expect($result)->toBe(['guid' => 'g-1', 'indexer_id' => 3, 'title' => 'x']);
});
