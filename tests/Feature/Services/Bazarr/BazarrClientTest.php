<?php

declare(strict_types=1);

use App\Cache\Services\BazarrCache;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function bazarrFixture(string $name): array
{
    $contents = file_get_contents(base_path(sprintf('tests/Fixtures/Bazarr/%s.json', $name)));

    throw_if($contents === false, RuntimeException::class, sprintf('Unable to read Bazarr fixture [%s].', $name));

    $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    throw_unless(is_array($fixture), RuntimeException::class, sprintf('Bazarr fixture [%s] is not an array or object.', $name));

    return $fixture;
}

function bazarrConnection(?int $id = 1, ServiceType $serviceType = ServiceType::Bazarr): ServiceConnection
{
    $connection = ServiceConnection::factory()->make([
        'type' => $serviceType,
        'url' => 'http://bazarr.local:6767',
        'api_key' => 'secret',
    ]);
    $connection->setAttribute('id', $id);

    return $connection;
}

function captureBazarrException(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        return $throwable;
    }

    throw new RuntimeException('Expected Bazarr client call to throw an exception.');
}

/**
 * @return array<string, mixed>
 */
function bazarrRequestQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    Cache::store('array')->flush();
    Http::preventStrayRequests();

    $this->connection = bazarrConnection();
    $this->client = new BazarrClient($this->connection);
});

test('system status preserves the data envelope and exposes the Bazarr version at the top level', function (): void {
    Http::fake([
        'bazarr.local:6767/api/system/status' => Http::response(bazarrFixture('system-status')),
    ]);

    $status = $this->client->getSystemStatus();

    expect($status)
        ->toMatchArray(bazarrFixture('system-status'))
        ->and($status['version'])->toBe('1.6.0');
});

test('data envelope methods preserve their upstream response', function (string $method, string $path, string $fixture): void {
    Http::fake([
        'bazarr.local:6767'.$path => Http::response(bazarrFixture($fixture)),
    ]);

    $result = $this->client->{$method}();

    expect($result)->toBe(bazarrFixture($fixture));
    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === $path
        && bazarrRequestQuery($request) === []);
})->with([
    'health' => ['getHealth', '/api/system/health', 'system-health'],
    'providers' => ['getProviders', '/api/providers', 'providers'],
    'tasks' => ['getTasks', '/api/system/tasks', 'tasks'],
]);

test('episodes accept one normalized identifier set and preserve the data envelope', function (
    array $seriesIds,
    array $episodeIds,
    string $queryKey,
): void {
    Http::fake([
        'bazarr.local:6767/api/episodes*' => Http::response(bazarrFixture('episodes')),
    ]);

    $result = $this->client->getEpisodes($seriesIds, $episodeIds);

    expect($result)->toBe(bazarrFixture('episodes'));
    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/episodes'
        && bazarrRequestQuery($request) === [$queryKey => ['2', '7']]);
})->with([
    'series IDs' => [[7, 2, 7, 0, -4], [], 'seriesid'],
    'episode IDs' => [[], [7, 2, 7, 0, -4], 'episodeid'],
]);

test('episodes reject missing or ambiguous identifier sets before HTTP', function (array $seriesIds, array $episodeIds): void {
    Http::fake();

    expect(fn (): array => $this->client->getEpisodes($seriesIds, $episodeIds))
        ->toThrow(InvalidArgumentException::class, 'exactly one');

    Http::assertNothingSent();
})->with([
    'both omitted' => [[], []],
    'both empty after normalization' => [[0, -1], [0, -2]],
    'both supplied' => [[1], [2]],
]);

test('page envelope methods send exact pagination and filter query names', function (
    string $method,
    array $arguments,
    string $path,
    string $fixture,
    array $query,
): void {
    Http::fake([
        sprintf('bazarr.local:6767%s*', $path) => Http::response(bazarrFixture($fixture)),
    ]);

    $result = $this->client->{$method}(...$arguments);

    expect($result)->toBe(bazarrFixture($fixture));
    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === $path
        && bazarrRequestQuery($request) === $query);
})->with([
    'movies' => ['getMovies', [10, 25], '/api/movies', 'movies', ['start' => '10', 'length' => '25']],
    'wanted episodes' => ['getWantedEpisodes', [20, 30], '/api/episodes/wanted', 'episodes-wanted', ['start' => '20', 'length' => '30']],
    'wanted movies' => ['getWantedMovies', [30, 40], '/api/movies/wanted', 'movies-wanted', ['start' => '30', 'length' => '40']],
    'episode history' => ['getEpisodeHistory', [40, 50, 701], '/api/episodes/history', 'episodes-history', ['start' => '40', 'length' => '50', 'episodeid' => '701']],
    'movie history' => ['getMovieHistory', [50, 60, 801], '/api/movies/history', 'movies-history', ['start' => '50', 'length' => '60', 'radarrid' => '801']],
]);

test('page envelope methods send default pagination parameters', function (
    string $method,
    string $path,
    string $fixture,
    ?string $optionalFilter,
): void {
    Http::fake([
        sprintf('bazarr.local:6767%s*', $path) => Http::response(bazarrFixture($fixture)),
    ]);

    $this->client->{$method}();

    Http::assertSent(function (Request $request) use ($path, $optionalFilter): bool {
        $query = bazarrRequestQuery($request);

        return parse_url($request->url(), PHP_URL_PATH) === $path
            && $query === ['start' => '0', 'length' => '50']
            && ($optionalFilter === null || ! array_key_exists($optionalFilter, $query));
    });
})->with([
    'movies' => ['getMovies', '/api/movies', 'movies', null],
    'wanted episodes' => ['getWantedEpisodes', '/api/episodes/wanted', 'episodes-wanted', null],
    'wanted movies' => ['getWantedMovies', '/api/movies/wanted', 'movies-wanted', null],
    'episode history' => ['getEpisodeHistory', '/api/episodes/history', 'episodes-history', 'episodeid'],
    'movie history' => ['getMovieHistory', '/api/movies/history', 'movies-history', 'radarrid'],
]);

test('history methods omit optional identifiers when they are null', function (
    string $method,
    string $path,
    string $fixture,
    string $filter,
): void {
    Http::fake([
        sprintf('bazarr.local:6767%s*', $path) => Http::response(bazarrFixture($fixture)),
    ]);

    $this->client->{$method}(5, 15);

    Http::assertSent(function (Request $request) use ($path, $filter): bool {
        $query = bazarrRequestQuery($request);

        return parse_url($request->url(), PHP_URL_PATH) === $path
            && $query === ['start' => '5', 'length' => '15']
            && ! array_key_exists($filter, $query);
    });
})->with([
    'episode history' => ['getEpisodeHistory', '/api/episodes/history', 'episodes-history', 'episodeid'],
    'movie history' => ['getMovieHistory', '/api/movies/history', 'movies-history', 'radarrid'],
]);

test('page methods reject invalid pagination before cache or HTTP', function (
    string $method,
    array $arguments,
): void {
    Http::fake();

    expect(fn (): array => $this->client->{$method}(...$arguments))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'movies negative start' => ['getMovies', [-1, 50]],
    'movies non-positive length' => ['getMovies', [0, 0]],
    'wanted episodes negative start' => ['getWantedEpisodes', [-1, 50]],
    'wanted episodes non-positive length' => ['getWantedEpisodes', [0, 0]],
    'wanted movies negative start' => ['getWantedMovies', [-1, 50]],
    'wanted movies non-positive length' => ['getWantedMovies', [0, 0]],
    'episode history negative start' => ['getEpisodeHistory', [-1, 50]],
    'episode history non-positive length' => ['getEpisodeHistory', [0, 0]],
    'movie history negative start' => ['getMovieHistory', [-1, 50]],
    'movie history non-positive length' => ['getMovieHistory', [0, 0]],
]);

test('history methods reject non-positive optional identifiers before cache or HTTP', function (
    string $method,
    array $arguments,
): void {
    Http::fake();

    expect(fn (): array => $this->client->{$method}(...$arguments))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'zero episode ID' => ['getEpisodeHistory', [0, 50, 0]],
    'negative episode ID' => ['getEpisodeHistory', [0, 50, -1]],
    'zero Radarr ID' => ['getMovieHistory', [0, 50, 0]],
    'negative Radarr ID' => ['getMovieHistory', [0, 50, -1]],
]);

test('Bazarr fixtures model tags as strings', function (string $fixture): void {
    $payload = bazarrFixture($fixture);

    expect($payload['data'])->toBeArray()->not->toBeEmpty();

    foreach ($payload['data'] as $row) {
        expect($row['tags'])->toBeArray()->not->toBeEmpty();

        foreach ($row['tags'] as $tag) {
            expect($tag)->toBeString();
        }
    }
})->with([
    'movies' => ['movies'],
    'wanted episodes' => ['episodes-wanted'],
    'wanted movies' => ['movies-wanted'],
    'episode history' => ['episodes-history'],
    'movie history' => ['movies-history'],
]);

test('movie history fixture models Bazarr 1.6.0 path marshalling as null', function (): void {
    $payload = bazarrFixture('movies-history');

    expect($payload['data'][0]['path'])->toBeNull();
});

test('language profiles preserve the validated bare list', function (): void {
    Http::fake([
        'bazarr.local:6767/api/system/languages/profiles' => Http::response(bazarrFixture('language-profiles')),
    ]);

    expect($this->client->getLanguageProfiles())->toBe(bazarrFixture('language-profiles'));
});

test('OpenAPI preserves the validated bare Swagger object', function (): void {
    Http::fake([
        'bazarr.local:6767/api/swagger.json' => Http::response(bazarrFixture('swagger')),
    ]);

    expect($this->client->getOpenApi())->toBe(bazarrFixture('swagger'));
});

test('every request authenticates only through the API key header and never logs the key', function (): void {
    $logs = [];
    Log::listen(function (MessageLogged $messageLogged) use (&$logs): void {
        $logs[] = $messageLogged->message.json_encode($messageLogged->context, JSON_THROW_ON_ERROR);
    });

    Http::fake([
        'bazarr.local:6767/api/system/status' => Http::response(bazarrFixture('system-status')),
        'bazarr.local:6767/api/system/health' => Http::response(bazarrFixture('system-health')),
        'bazarr.local:6767/api/movies/wanted*' => Http::response(bazarrFixture('movies-wanted')),
        'bazarr.local:6767/api/movies/history*' => Http::response(bazarrFixture('movies-history')),
        'bazarr.local:6767/api/movies*' => Http::response(bazarrFixture('movies')),
        'bazarr.local:6767/api/episodes/wanted*' => Http::response(bazarrFixture('episodes-wanted')),
        'bazarr.local:6767/api/episodes/history*' => Http::response(bazarrFixture('episodes-history')),
        'bazarr.local:6767/api/episodes*' => Http::response(bazarrFixture('episodes')),
        'bazarr.local:6767/api/providers' => Http::response(bazarrFixture('providers')),
        'bazarr.local:6767/api/system/tasks' => Http::response(bazarrFixture('tasks')),
        'bazarr.local:6767/api/system/languages/profiles' => Http::response(bazarrFixture('language-profiles')),
        'bazarr.local:6767/api/swagger.json' => Http::response(bazarrFixture('swagger')),
    ]);

    $this->client->getSystemStatus();
    $this->client->getHealth();
    $this->client->getEpisodes(seriesIds: [100]);
    $this->client->getMovies();
    $this->client->getWantedEpisodes();
    $this->client->getWantedMovies();
    $this->client->getEpisodeHistory();
    $this->client->getMovieHistory();
    $this->client->getProviders();
    $this->client->getTasks();
    $this->client->getLanguageProfiles();
    $this->client->getOpenApi();

    $requests = Http::recorded();

    expect($requests)->toHaveCount(12);

    foreach ($requests as [$request]) {
        expect($request)
            ->method()->toBe('GET')
            ->hasHeader('X-API-KEY', 'secret')->toBeTrue()
            ->body()->toBe('')
            ->and($request->url())->not->toContain('secret');
    }

    expect(implode("\n", $logs))->not->toContain('secret');
});

test('the constructor rejects non-Bazarr service connections', function (): void {
    expect(fn (): BazarrClient => new BazarrClient(bazarrConnection(serviceType: ServiceType::Sonarr)))
        ->toThrow(InvalidArgumentException::class, 'Bazarr');
});

test('the client rejects unpersisted service connections before HTTP', function (?int $id): void {
    Http::fake();

    expect(fn (): BazarrClient => new BazarrClient(bazarrConnection(id: $id)))
        ->toThrow(InvalidArgumentException::class, 'persisted');

    Http::assertNothingSent();
})->with([
    'null ID' => null,
    'zero ID' => 0,
]);

test('the cache independently rejects unpersisted service connections', function (?int $id): void {
    expect(fn (): BazarrCache => new BazarrCache(bazarrConnection(id: $id)))
        ->toThrow(InvalidArgumentException::class, 'persisted');
})->with([
    'null ID' => null,
    'zero ID' => 0,
]);

test('finds a movie past the first Bazarr page by walking paginated results', function (): void {
    // Radarr movie 942 sits on the second Bazarr page (offset 100). A single
    // length-100 lookup would miss it and treat it as permanently not-found.
    Http::fake([
        'bazarr.local:6767/api/movies*' => Http::sequence()
            ->push([
                'data' => array_map(
                    static fn (int $index): array => ['radarrId' => $index + 1, 'title' => 'Movie '.($index + 1)],
                    range(0, 99),
                ),
                'total' => 150,
            ])
            ->push([
                'data' => [
                    ['radarrId' => 942, 'title' => 'Deep Catalogue Movie'],
                    ['radarrId' => 943, 'title' => 'Another'],
                ],
                'total' => 150,
            ]),
    ]);

    $movie = $this->client->findMovieByRadarrId(942);

    expect($movie)->toBeArray()
        ->and($movie['radarrId'])->toBe(942)
        ->and($movie['title'])->toBe('Deep Catalogue Movie');
    Http::assertSentCount(2);
});

test('returns null for a Radarr movie absent from every paginated page', function (): void {
    Http::fake([
        'bazarr.local:6767/api/movies*' => Http::response([
            'data' => [['radarrId' => 1, 'title' => 'Only Movie']],
            'total' => 1,
        ]),
    ]);

    expect($this->client->findMovieByRadarrId(999))->toBeNull();
});

test('identical reads use the cache without a second HTTP request', function (): void {
    Http::fake([
        'bazarr.local:6767/api/movies*' => Http::response(bazarrFixture('movies')),
    ]);

    $this->client->getMovies(10, 25);
    $this->client->getMovies(10, 25);

    Http::assertSentCount(1);
});

test('cache entries are isolated by service connection', function (): void {
    Http::fake([
        'bazarr.local:6767/api/movies*' => Http::sequence()
            ->push(bazarrFixture('movies'))
            ->push(['data' => [['radarrId' => 999, 'title' => 'Second connection']], 'total' => 1]),
    ]);

    $first = new BazarrClient(bazarrConnection(id: 10))->getMovies();
    $second = new BazarrClient(bazarrConnection(id: 20))->getMovies();

    expect($first)->not->toBe($second);
    Http::assertSentCount(2);
});

test('cache keys vary by method pagination and optional filter identifiers', function (): void {
    Http::fake([
        'bazarr.local:6767/api/episodes*' => Http::response(bazarrFixture('episodes')),
        'bazarr.local:6767/api/movies/wanted*' => Http::response(bazarrFixture('movies-wanted')),
        'bazarr.local:6767/api/movies/history*' => Http::response(bazarrFixture('movies-history')),
        'bazarr.local:6767/api/movies*' => Http::response(bazarrFixture('movies')),
    ]);

    $this->client->getEpisodes(seriesIds: [7, 2]);
    $this->client->getEpisodes(seriesIds: [2, 7, 2]);
    $this->client->getEpisodes(episodeIds: [2, 7]);
    $this->client->getMovies(0, 10);
    $this->client->getMovies(10, 10);
    $this->client->getMovies(10, 20);
    $this->client->getWantedMovies(0, 10);
    $this->client->getMovieHistory(0, 10);
    $this->client->getMovieHistory(0, 10, 801);
    $this->client->getMovieHistory(0, 10, 802);
    $this->client->getMovieHistory(0, 10, 802);

    Http::assertSentCount(9);
});

test('client errors throw without retrying', function (int $status): void {
    Http::fake([
        'bazarr.local:6767/api/system/health' => Http::response(['message' => 'upstream rejected request'], $status),
    ]);

    $throwable = captureBazarrException(fn (): array => $this->client->getHealth());

    expect($throwable)
        ->toBeInstanceOf(RequestException::class)
        ->and($throwable->getMessage())->not->toContain('secret');

    Http::assertSentCount(1);
})->with([401, 404]);

test('server errors retry twice and then throw', function (): void {
    Http::fake([
        'bazarr.local:6767/api/system/health' => Http::sequence()
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
    ]);

    $throwable = captureBazarrException(fn (): array => $this->client->getHealth());

    expect($throwable)
        ->toBeInstanceOf(RequestException::class)
        ->and($throwable->getMessage())->not->toContain('secret');

    Http::assertSentCount(3);
});

test('connection failures retry twice and then throw', function (): void {
    Http::fake([
        'bazarr.local:6767/api/system/health' => Http::failedConnection(),
    ]);

    $throwable = captureBazarrException(fn (): array => $this->client->getHealth());

    expect($throwable)
        ->toBeInstanceOf(ConnectionException::class)
        ->and($throwable->getMessage())->not->toContain('secret');

    Http::assertSentCount(3);
});

test('malformed JSON and invalid response shapes never become successful empty results', function (
    string $method,
    array $arguments,
    mixed $payload,
): void {
    Http::fake([
        '*' => Http::response($payload, 200, ['Content-Type' => 'application/json']),
    ]);

    expect(fn (): array => $this->client->{$method}(...$arguments))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'malformed JSON' => ['getHealth', [], '{not-json'],
    'invalid data envelope' => ['getHealth', [], ['data' => 'not-an-array']],
    'invalid data list row' => ['getEpisodes', [[1], []], ['data' => ['not-an-object']]],
    'invalid page total' => ['getMovies', [], ['data' => [], 'total' => '1']],
    'invalid page row' => ['getWantedEpisodes', [], ['data' => ['not-an-object'], 'total' => 1]],
    'invalid profiles list' => ['getLanguageProfiles', [], ['not-an-object']],
    'invalid OpenAPI object' => ['getOpenApi', [], []],
]);

test('JSON response contracts preserve object and list identity', function (
    string $method,
    array $arguments,
    string $body,
): void {
    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'application/json']),
    ]);

    expect(fn (): array => $this->client->{$method}(...$arguments))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'status root must be an object' => ['getSystemStatus', [], '[]'],
    'status data must be an object' => ['getSystemStatus', [], '{"data":[]}'],
    'list envelope root must be an object' => ['getHealth', [], '[]'],
    'list envelope data must be a list' => ['getHealth', [], '{"data":{}}'],
    'list envelope rows must be objects' => ['getHealth', [], '{"data":[[]]}'],
    'page root must be an object' => ['getMovies', [], '[]'],
    'page data must be a list' => ['getMovies', [], '{"data":{},"total":0}'],
    'page rows must be objects' => ['getMovies', [], '{"data":[[]],"total":1}'],
    'page total must not be negative' => ['getMovies', [], '{"data":[],"total":-1}'],
    'profiles root must be a list' => ['getLanguageProfiles', [], '{}'],
    'profile rows must be objects' => ['getLanguageProfiles', [], '[[]]'],
    'Swagger root must be an object' => ['getOpenApi', [], '[]'],
    'Swagger info must be an object' => ['getOpenApi', [], '{"swagger":"2.0","info":[],"paths":{}}'],
    'Swagger paths must be an object' => ['getOpenApi', [], '{"swagger":"2.0","info":{},"paths":[]}'],
]);

test('empty JSON objects remain valid object rows and Swagger sections', function (
    string $method,
    string $body,
    array $expected,
): void {
    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'application/json']),
    ]);

    expect($this->client->{$method}())->toBe($expected);
})->with([
    'list envelope object row' => ['getHealth', '{"data":[{}]}', ['data' => [[]]]],
    'page object row' => ['getMovies', '{"data":[{}],"total":1}', ['data' => [[]], 'total' => 1]],
    'profile object row' => ['getLanguageProfiles', '[{}]', [[]]],
    'empty Swagger objects' => ['getOpenApi', '{"swagger":"2.0","info":{},"paths":{}}', ['swagger' => '2.0', 'info' => [], 'paths' => []]],
]);

test('HTTP errors are thrown before malformed JSON is decoded', function (): void {
    Http::fake([
        '*' => Http::response('{not-json', 404, ['Content-Type' => 'application/json']),
    ]);

    expect(fn (): array => $this->client->getHealth())
        ->toThrow(RequestException::class);
});
