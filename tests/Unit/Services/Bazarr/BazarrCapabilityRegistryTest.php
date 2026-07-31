<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrCapabilityRegistry;
use App\Services\Bazarr\BazarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array<string, bool>
 */
function expectedBazarrCapabilities(
    bool $inventory = true,
    bool $wanted = true,
    bool $history = true,
    bool $manualSearch = true,
    bool $bestDownload = true,
    bool $exactDownload = true,
    bool $upload = true,
    bool $delete = true,
    bool $sync = true,
    bool $translate = true,
    bool $episodeMediaAction = true,
    bool $movieMediaAction = true,
    bool $tasks = true,
    bool $languageProfiles = true,
    bool $settingsAdapter = false,
    bool $notificationAdapter = false,
): array {
    return [
        'inventory' => $inventory,
        'wanted' => $wanted,
        'history' => $history,
        'manual_search' => $manualSearch,
        'best_download' => $bestDownload,
        'exact_download' => $exactDownload,
        'upload' => $upload,
        'delete' => $delete,
        'sync' => $sync,
        'translate' => $translate,
        'episode_media_action' => $episodeMediaAction,
        'movie_media_action' => $movieMediaAction,
        'tasks' => $tasks,
        'language_profiles' => $languageProfiles,
        'settings_adapter' => $settingsAdapter,
        'notification_adapter' => $notificationAdapter,
    ];
}

/**
 * @param  list<string>  $missingPaths
 * @return array<string, mixed>
 */
function completeBazarrSwagger(
    array $missingPaths = [],
    string $basePath = '/api',
    bool $prefixPaths = false,
    bool $uppercaseMethods = false,
    bool $includeAdapterRoutes = false,
): array {
    $paths = [
        '/episodes' => ['get'],
        // The media collections also carry the media-action write.
        '/movies' => ['get', 'patch'],
        '/series' => ['patch'],
        '/episodes/wanted' => ['get'],
        '/movies/wanted' => ['get'],
        '/episodes/history' => ['get'],
        '/movies/history' => ['get'],
        '/providers/episodes' => ['get', 'post'],
        '/providers/movies' => ['get', 'post'],
        '/episodes/subtitles' => ['patch', 'post', 'delete'],
        '/movies/subtitles' => ['patch', 'post', 'delete'],
        '/subtitles' => ['patch'],
        '/system/tasks' => ['get', 'post'],
        '/system/languages/profiles' => ['get'],
    ];

    if ($includeAdapterRoutes) {
        $paths['/system/settings'] = ['get', 'post'];
        $paths['/system/notifications'] = ['get', 'post', 'patch'];
    }

    foreach ($missingPaths as $missingPath) {
        unset($paths[$missingPath]);
    }

    $swaggerPaths = [];

    foreach ($paths as $path => $methods) {
        $swaggerPath = $prefixPaths ? '/api'.$path : $path;

        foreach ($methods as $method) {
            $swaggerPaths[$swaggerPath][$uppercaseMethods ? strtoupper($method) : $method] = [
                'responses' => ['200' => ['description' => 'Success']],
            ];
        }
    }

    return [
        'swagger' => '2.0',
        'basePath' => $basePath,
        'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
        'paths' => $swaggerPaths,
    ];
}

function capabilityBazarrConnection(?int $id = 91, ServiceType $serviceType = ServiceType::Bazarr): ServiceConnection
{
    $connection = ServiceConnection::factory()->make([
        'type' => $serviceType,
        'url' => 'http://bazarr.local:6767',
        'api_key' => 'discovery-secret',
    ]);
    $connection->setAttribute('id', $id);

    return $connection;
}

/**
 * @return array{data: list<array<string, mixed>>, total: int}
 */
function emptyBazarrPage(): array
{
    return ['data' => [], 'total' => 0];
}

/**
 * @param  array<string, mixed>|string  $swaggerResponse
 */
function fakeBazarrCapabilityDiscovery(array|string $swaggerResponse, int $swaggerStatus = 200): void
{
    Http::fake([
        'bazarr.local:6767/api/swagger.json' => Http::response($swaggerResponse, $swaggerStatus),
        'bazarr.local:6767/api/episodes/wanted*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/movies/wanted*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/episodes/history*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/movies/history*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/system/languages/profiles' => Http::response([]),
    ]);
}

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    Cache::store('array')->flush();
    Http::preventStrayRequests();

    $this->registry = new BazarrCapabilityRegistry;
    $this->client = new BazarrClient(capabilityBazarrConnection());
});

test('complete Swagger enables every supported capability in stable order', function (): void {
    $capabilities = $this->registry->detect(completeBazarrSwagger(includeAdapterRoutes: true));

    expect($capabilities)
        ->toBe(expectedBazarrCapabilities(settingsAdapter: true, notificationAdapter: true))
        ->and(array_keys($capabilities))->toBe(array_keys(expectedBazarrCapabilities()));
});

test('a missing Swagger path disables only capabilities that depend on it', function (
    array $missingPaths,
    array $expected,
): void {
    expect($this->registry->detect(completeBazarrSwagger(missingPaths: $missingPaths)))->toBe($expected);
})->with([
    'shared subtitles route' => [
        ['/subtitles'],
        expectedBazarrCapabilities(sync: false, translate: false),
    ],
    'one side of inventory pair' => [
        ['/movies'],
        expectedBazarrCapabilities(inventory: false, movieMediaAction: false),
    ],
    'episode media action route' => [
        ['/series'],
        expectedBazarrCapabilities(episodeMediaAction: false),
    ],
    'one side of provider pair' => [
        ['/providers/movies'],
        expectedBazarrCapabilities(manualSearch: false, exactDownload: false),
    ],
    'one side of subtitle mutations' => [
        ['/episodes/subtitles'],
        expectedBazarrCapabilities(bestDownload: false, upload: false, delete: false),
    ],
]);

test('a missing Swagger method disables only capabilities that depend on it', function (): void {
    $swagger = completeBazarrSwagger();
    unset($swagger['paths']['/system/tasks']['post']);

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities(tasks: false));
});

test('a structurally valid Swagger operation accepts normalized numeric response keys', function (): void {
    $swagger = completeBazarrSwagger();
    $swagger['paths']['/episodes']['get'] = [
        'responses' => [200 => ['description' => 'Success']],
    ];
    $swagger['paths']['/episodes']['parameters'] = [
        ['name' => 'seriesid', 'in' => 'query', 'type' => 'integer'],
    ];
    $swagger['paths']['/episodes']['x-bazarr-capability-test'] = true;

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities());
});

test('a malformed required Swagger operation disables only its dependent capability', function (array $operation): void {
    $swagger = completeBazarrSwagger();
    $swagger['paths']['/episodes']['get'] = $operation;

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities(inventory: false));
})->with([
    'empty operation list' => [[]],
    'operation missing responses' => [['summary' => 'Missing responses']],
    'operation with empty responses' => [['responses' => []]],
    'responses map is a list' => [['responses' => [['description' => 'Success']]]],
    'unknown-only response key' => [['responses' => ['banana' => ['description' => 'No status']]]],
    'extension-only responses' => [['responses' => ['x-bazarr-note' => ['description' => 'Extension']]]],
    'status response is scalar' => [['responses' => [200 => 'Success']]],
    'status response is a list' => [['responses' => [200 => ['Success']]]],
    'status response is missing description' => [['responses' => [200 => ['headers' => []]]]],
    'default response is scalar' => [['responses' => ['default' => 'Failure']]],
    'default response is a list' => [['responses' => ['default' => ['Failure']]]],
    'default response is missing description' => [['responses' => ['default' => ['headers' => []]]]],
    'reference response has empty ref' => [['responses' => [200 => ['$ref' => '']]]],
    'reference response has non-string ref' => [['responses' => [200 => ['$ref' => 42]]]],
]);

test('a valid Swagger response entry makes its operation available', function (array $responses): void {
    $swagger = completeBazarrSwagger();
    $swagger['paths']['/episodes']['get'] = ['responses' => $responses];

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities());
})->with([
    'numeric status response object' => [[200 => ['description' => 'Success']]],
    'response object with empty string description' => [[200 => ['description' => '']]],
    'default response object' => [['default' => ['description' => 'Failure']]],
    'response reference object' => [[200 => ['$ref' => '#/responses/Success']]],
    'irrelevant extension beside valid response' => [[
        'x-bazarr-note' => true,
        200 => ['description' => 'Success'],
    ]],
]);

test('Paths Object extensions do not affect capability detection', function (): void {
    $swagger = completeBazarrSwagger();
    $swagger['paths']['x-bazarr-generated-at'] = '2026-07-16';

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities());
});

test('a malformed unrelated operation does not affect required capabilities', function (): void {
    $swagger = completeBazarrSwagger();
    $swagger['paths']['/system/ping'] = ['get' => []];

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities());
});

test('unknown and malformed irrelevant methods do not invalidate a required operation', function (): void {
    $swagger = completeBazarrSwagger();
    $swagger['paths']['/episodes']['brew'] = ['responses' => [200 => ['description' => 'Unknown']]];
    $swagger['paths']['/episodes']['post'] = [];

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities());
});

test('malformed root Swagger structures remain unavailable', function (array $swagger): void {
    expect(fn (): array => $this->registry->detect($swagger))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'unsupported Swagger version' => [[
        'swagger' => '3.0',
        'basePath' => '/api',
        'paths' => [],
    ]],
    'malformed basePath' => [[
        'swagger' => '2.0',
        'basePath' => [],
        'paths' => [],
    ]],
    'malformed Paths Object' => [[
        'swagger' => '2.0',
        'basePath' => '/api',
        'paths' => ['/episodes'],
    ]],
]);

test('Swagger base paths API prefixes and method casing normalize to the same matrix', function (
    string $basePath,
    bool $prefixPaths,
    bool $uppercaseMethods,
): void {
    $swagger = completeBazarrSwagger(
        basePath: $basePath,
        prefixPaths: $prefixPaths,
        uppercaseMethods: $uppercaseMethods,
    );

    expect($this->registry->detect($swagger))->toBe(expectedBazarrCapabilities());
})->with([
    'Bazarr basePath' => ['/api', false, false],
    'API-prefixed paths without basePath' => ['', true, false],
    'already canonical paths' => ['', false, false],
    'API basePath with already prefixed paths' => ['/api', true, false],
    'uppercase operation keys' => ['/api', false, true],
]);

test('successful Swagger discovery is cached per connection', function (): void {
    fakeBazarrCapabilityDiscovery(completeBazarrSwagger());
    $expected = expectedBazarrCapabilities();

    expect($this->client->getCapabilities())
        ->toBe($expected)
        ->and($this->client->getCapabilities())->toBe($expected)
        ->and(Cache::store('array')->tags(['bazarr:91'])->get('bazarr:91:capabilities'))->toBe($expected);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://bazarr.local:6767/api/swagger.json');
});

test('capability caches are isolated by Bazarr connection', function (): void {
    $secondSwagger = completeBazarrSwagger(missingPaths: ['/movies']);
    Http::fake([
        'bazarr.local:6767/api/swagger.json' => Http::sequence()
            ->push(completeBazarrSwagger())
            ->push($secondSwagger),
    ]);

    $firstCapabilities = $this->client->getCapabilities();
    $secondCapabilities = new BazarrClient(capabilityBazarrConnection(id: 92))->getCapabilities();

    expect($firstCapabilities)
        ->toBe(expectedBazarrCapabilities())
        ->and($secondCapabilities)->toBe(expectedBazarrCapabilities(inventory: false, movieMediaAction: false))
        ->and(Cache::store('array')->tags(['bazarr:91'])->get('bazarr:91:capabilities'))->toBe($firstCapabilities)
        ->and(Cache::store('array')->tags(['bazarr:92'])->get('bazarr:92:capabilities'))->toBe($secondCapabilities);

    Http::assertSentCount(2);
});

test('unavailable or malformed Swagger falls back to bounded safe reads', function (
    Closure $swaggerFake,
): void {
    Http::fake([
        'bazarr.local:6767/api/swagger.json' => $swaggerFake(),
        'bazarr.local:6767/api/episodes/wanted*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/movies/wanted*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/episodes/history*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/movies/history*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/system/languages/profiles' => Http::response([]),
    ]);

    expect($this->client->getCapabilities())->toBe(expectedBazarrCapabilities(
        inventory: false,
        manualSearch: false,
        bestDownload: false,
        exactDownload: false,
        upload: false,
        delete: false,
        sync: false,
        translate: false,
        episodeMediaAction: false,
        movieMediaAction: false,
        tasks: false,
    ));

    $fallbackPaths = collect(Http::recorded())
        ->map(fn (array $record): string => (string) parse_url((string) $record[0]->url(), PHP_URL_PATH))
        ->filter(fn (string $path): bool => $path !== '/api/swagger.json')
        ->values()
        ->all();

    expect($fallbackPaths)->toBe([
        '/api/episodes/wanted',
        '/api/movies/wanted',
        '/api/episodes/history',
        '/api/movies/history',
        '/api/system/languages/profiles',
    ]);
})->with([
    '404 response' => [fn (): mixed => Http::response([], 404)],
    'connection failure' => [fn (): mixed => Http::failedConnection()],
    'malformed JSON' => [fn (): mixed => Http::response('{not-json', 200)],
    'malformed Swagger shape' => [fn (): mixed => Http::response(['swagger' => '2.0', 'info' => [], 'paths' => []])],
]);

test('fallback probes exact bounded query parameters without unsafe requests', function (): void {
    fakeBazarrCapabilityDiscovery([], 404);

    $this->client->getCapabilities();

    $fallbackRequests = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->reject(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/swagger.json')
        ->values();

    expect($fallbackRequests)->toHaveCount(5);

    foreach ($fallbackRequests as $fallbackRequest) {
        $path = (string) parse_url($fallbackRequest->url(), PHP_URL_PATH);
        parse_str((string) parse_url($fallbackRequest->url(), PHP_URL_QUERY), $query);

        expect($fallbackRequest->method())->toBe('GET');

        if ($path === '/api/system/languages/profiles') {
            expect($query)->toBe([]);
        } else {
            expect($query)->toBe(['start' => '0', 'length' => '1']);
        }

        expect($path)
            ->not->toContain('/providers/')
            ->not->toBe('/api/episodes')
            ->not->toBe('/api/system/tasks');
    }
});

test('partial fallback failures disable only dependent capabilities and do not stop later probes', function (): void {
    Http::fake([
        'bazarr.local:6767/api/swagger.json' => Http::response([], 404),
        'bazarr.local:6767/api/episodes/wanted*' => Http::response([], 404),
        'bazarr.local:6767/api/movies/wanted*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/episodes/history*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/movies/history*' => Http::response(emptyBazarrPage()),
        'bazarr.local:6767/api/system/languages/profiles' => Http::response([]),
    ]);

    expect($this->client->getCapabilities())->toBe(expectedBazarrCapabilities(
        inventory: false,
        wanted: false,
        manualSearch: false,
        bestDownload: false,
        exactDownload: false,
        upload: false,
        delete: false,
        sync: false,
        translate: false,
        episodeMediaAction: false,
        movieMediaAction: false,
        tasks: false,
    ));

    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/system/languages/profiles');
    Http::assertSentCount(6);
});

test('conservative fallback results are cached', function (): void {
    fakeBazarrCapabilityDiscovery([], 404);

    $first = $this->client->getCapabilities();
    $second = $this->client->getCapabilities();

    expect($second)
        ->toBe($first)
        ->and(Cache::store('array')->tags(['bazarr:91'])->get('bazarr:91:capabilities'))->toBe($first);
    Http::assertSentCount(6);
});

test('discovery keeps the API key in the header only', function (): void {
    fakeBazarrCapabilityDiscovery([], 404);

    $this->client->getCapabilities();

    foreach (Http::recorded() as [$request]) {
        expect($request)
            ->hasHeader('X-API-KEY', 'discovery-secret')->toBeTrue()
            ->body()->toBe('')
            ->and($request->url())->not->toContain('discovery-secret');
    }
});

test('capability discovery keeps the client constructor connection invariants', function (): void {
    expect(fn (): BazarrClient => new BazarrClient(capabilityBazarrConnection(serviceType: ServiceType::Sonarr)))
        ->toThrow(InvalidArgumentException::class, 'Bazarr')
        ->and(fn (): BazarrClient => new BazarrClient(capabilityBazarrConnection(id: null)))
        ->toThrow(InvalidArgumentException::class, 'persisted');
});
