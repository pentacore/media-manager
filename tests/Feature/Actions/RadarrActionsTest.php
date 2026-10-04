<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Arr\SearchCommandFailed;
use App\Services\MediaReplacement\ReplacementInFlight;
use App\Services\Radarr\RadarrActions;
use App\Services\Radarr\RadarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);
});

function radarrActionsConnectionId(): int
{
    return ServiceConnection::query()->where('type', 'radarr')->firstOrFail()->id;
}

test('deleteMovie sends DELETE to radarr with deleteFiles flag', function (): void {
    Http::fake(['radarr.local:7878/api/v3/movie/99*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'delete_movie',
        'payload' => ['radarr_movie_id' => 99, 'delete_files' => true],
    ]);

    $result = (new RadarrActions)->execute($request);

    expect($result)->toMatchArray(['radarr_movie_id' => 99, 'delete_files' => true]);

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/movie/99')
        && str_contains((string) $r->url(), 'deleteFiles=true'));
});

test('deleteMovie throws when radarr_movie_id is missing', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'delete_movie', 'payload' => []]);

    expect(fn (): array => (new RadarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('execute throws for unknown type', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'zzz', 'payload' => []]);

    expect(fn (): array => (new RadarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('monitor_movie is refused while a replacement for the movie is in flight', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => ['service' => 'radarr', 'service_connection_id' => ServiceConnection::query()->firstOrFail()->id, 'movie_id' => 10]],
    ]);

    expect(fn (): array => resolve(RadarrActions::class)->execute(ActionRequest::factory()->create([
        'type' => 'monitor_movie',
        'payload' => ['movie_id' => 10, 'monitored' => false],
    ])))->toThrow(ReplacementInFlight::class);

    Http::assertNothingSent();
});

test('the pinned-connection executors refuse to run without a pinned connection', function (string $type, array $payload): void {
    expect(fn (): array => resolve(RadarrActions::class)->execute(ActionRequest::factory()->create(['type' => $type, 'payload' => $payload])))
        ->toThrow(InvalidArgumentException::class, 'not pinned to a Radarr connection');

    Http::assertNothingSent();
})->with([
    'search_media' => ['search_media', ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10]]],
    'grab_release' => ['grab_release', ['service' => 'radarr', 'movie_id' => 10, 'guid' => 'g-2', 'indexer_id' => 4, 'release' => ['title' => 'x']]],
]);

test('the pinned-connection executors refuse to run against a mismatched connection type', function (string $type, array $payload): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    expect(fn (): array => resolve(RadarrActions::class)->execute(ActionRequest::factory()->create([
        'type' => $type,
        'payload' => [...$payload, 'service_connection_id' => $sonarr->id],
    ])))->toThrow(InvalidArgumentException::class, 'not pinned to a Radarr connection');

    Http::assertNothingSent();
})->with([
    'search_media' => ['search_media', ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10]]],
    'grab_release' => ['grab_release', ['service' => 'radarr', 'movie_id' => 10, 'guid' => 'g-2', 'indexer_id' => 4, 'release' => ['title' => 'x']]],
]);

test('search_media runs the matching Radarr command', function (string $command, array $payload, array $body): void {
    Http::fake(['radarr.local:7878/api/v3/command' => Http::response(['id' => 9], 201)]);

    (new RadarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'search_media',
        'payload' => ['service' => 'radarr', 'command' => $command, ...$payload, 'service_connection_id' => radarrActionsConnectionId()],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->data() === $body);
})->with([
    'movie' => ['movies_search', ['movie_ids' => [10]], ['name' => 'MoviesSearch', 'movieIds' => [10]]],
    'all missing' => ['missing_movies_search', [], ['name' => 'MissingMoviesSearch']],
    'all cutoff unmet' => ['cutoff_unmet_movies_search', [], ['name' => 'CutoffUnmetMoviesSearch']],
]);

test('a library-wide movie search that never gets a confirmed response fails without HTTP-level retry', function (): void {
    $attempts = 0;
    Http::fake(['radarr.local:7878/api/v3/command' => function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('reset');
    }]);

    expect(fn (): array => (new RadarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'search_media',
        'payload' => ['service' => 'radarr', 'command' => 'missing_movies_search', 'service_connection_id' => radarrActionsConnectionId()],
    ])))->toThrow(SearchCommandFailed::class, 'did not confirm this library-wide search');

    // withRetry: false means exactly one attempt — the generic 3x HTTP retry
    // must not have fired for a library-wide search.
    expect($attempts)->toBe(1);
});

test('grab_release posts the release to Radarr', function (): void {
    Http::fake(['radarr.local:7878/api/v3/release' => Http::response([], 200)]);

    (new RadarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'radarr', 'movie_id' => 10, 'guid' => 'g-2', 'indexer_id' => 4, 'release' => ['title' => 'Movie.2026.1080p'], 'service_connection_id' => radarrActionsConnectionId()],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->data() === ['guid' => 'g-2', 'indexerId' => 4]);
});

test('grab_release completes even when busting the cache afterward fails', function (): void {
    Http::fake(['radarr.local:7878/api/v3/release' => Http::response([], 200)]);
    config()->set('mediamanager.cache.store', 'this-store-does-not-exist');

    $result = (new RadarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'radarr', 'movie_id' => 10, 'guid' => 'g-2', 'indexer_id' => 4, 'release' => ['title' => 'x'], 'service_connection_id' => radarrActionsConnectionId()],
    ]));

    expect($result)->toBe(['indexer_id' => 4, 'title' => 'x']);
});

test('a movie write starts from what Radarr holds now, not from a cached snapshot', function (string $type, array $payload, array $changedInRadarr, array $expectedPut): void {
    $upstream = new stdClass;
    $upstream->movie = ['id' => 42, 'title' => 'Dune', 'monitored' => true, 'qualityProfileId' => 1];
    $upstream->puts = [];
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => function (Request $request) use ($upstream) {
            if ($request->method() === 'PUT') {
                $upstream->puts[] = $request->data();

                return Http::response($request->data());
            }

            return Http::response($upstream->movie);
        },
    ]);

    new RadarrClient(ServiceConnection::query()->where('type', 'radarr')->sole())->getMovieById(42);
    $upstream->movie = [...$upstream->movie, ...$changedInRadarr];

    resolve(RadarrActions::class)->execute(ActionRequest::factory()->create(['type' => $type, 'payload' => $payload]));

    expect($upstream->puts)->toHaveCount(1)
        ->and($upstream->puts[0])->toMatchArray($expectedPut);
})->with([
    'monitoring keeps a profile changed in Radarr' => ['monitor_movie', ['movie_id' => 42, 'monitored' => false], ['qualityProfileId' => 4], ['monitored' => false, 'qualityProfileId' => 4]],
    'a profile change keeps monitoring changed in Radarr' => ['set_movie_quality_profile', ['movie_id' => 42, 'quality_profile_id' => 7], ['monitored' => false], ['monitored' => false, 'qualityProfileId' => 7]],
]);

test('a malformed Radarr id says whether it is missing or invalid, and nothing is sent', function (string $type, array $payload, string $message): void {
    expect(fn (): array => (new RadarrActions)->execute(ActionRequest::factory()->create(['type' => $type, 'payload' => $payload])))
        ->toThrow(function (InvalidArgumentException $invalidArgumentException) use ($message): void {
            expect($invalidArgumentException->getMessage())->toBe($message);
        });

    Http::assertNothingSent();
})->with([
    'delete without an id' => ['delete_movie', [], 'radarr_movie_id is required'],
    'delete with a non-numeric id' => ['delete_movie', ['radarr_movie_id' => 'abc'], 'radarr_movie_id must be a positive integer'],
    'add with a zero tmdb id' => ['add_movie', ['tmdb_id' => 0], 'tmdb_id must be a positive integer'],
    'monitor without a movie' => ['monitor_movie', ['monitored' => false], 'movie_id is required'],
    'profile without a profile' => ['set_movie_quality_profile', ['movie_id' => 42], 'quality_profile_id is required'],
    'profile with a negative profile' => ['set_movie_quality_profile', ['movie_id' => 42, 'quality_profile_id' => -3], 'quality_profile_id must be a positive integer'],
]);
