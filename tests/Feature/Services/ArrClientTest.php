<?php

use App\Models\ServiceConnection;
use App\Services\Arr\ArrUnexpectedResponse;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test-api-key',
    ]);
});

test('sends X-Api-Key header with requests', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response(['appName' => 'Sonarr']),
    ]);

    $client = new SonarrClient($this->connection);
    $client->getSystemStatus();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'test-api-key'));
});

test('uses correct base URL without trailing slash', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989/',
        'api_key' => 'key',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response(['appName' => 'Sonarr']),
    ]);

    $client = new SonarrClient($connection);
    $client->getSystemStatus();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.local:8989/api/v3/system/status'));
});

test('getSystemStatus returns parsed response', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response([
            'appName' => 'Sonarr',
            'version' => '4.0.0.1',
            'buildTime' => '2024-01-01T00:00:00Z',
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getSystemStatus();

    expect($result)->toMatchArray([
        'appName' => 'Sonarr',
        'version' => '4.0.0.1',
    ]);
});

test('getQualityProfiles returns array of profiles', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'HD-1080p'],
            ['id' => 2, 'name' => '4K'],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getQualityProfiles();

    expect($result)->toHaveCount(2);
    expect($result[0]['name'])->toBe('HD-1080p');
});

test('getRootFolders returns array of folders', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/tv', 'freeSpace' => 500000000000],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getRootFolders();

    expect($result)->toHaveCount(1);
    expect($result[0]['path'])->toBe('/tv');
});

test('getDiskSpace returns disk entries', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/diskspace' => Http::response([
            ['path' => '/tv', 'freeSpace' => 500000000000, 'totalSpace' => 1000000000000],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getDiskSpace();

    expect($result)->toHaveCount(1);
    expect($result[0]['totalSpace'])->toBe(1000000000000);
});

test('runCommand sends correct POST body', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 1, 'name' => 'RefreshSeries']),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->runCommand('RefreshSeries', ['seriesId' => 42]);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->method() === 'POST'
            && $body['name'] === 'RefreshSeries'
            && $body['seriesId'] === 42;
    });

    expect($result['name'])->toBe('RefreshSeries');
});

test('throws RequestException on server error', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response([], 500),
    ]);

    $client = new SonarrClient($this->connection);
    $client->getSystemStatus();
})->throws(RequestException::class);

test('throws on client error', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $client = new SonarrClient($this->connection);
    $client->getSystemStatus();
})->throws(RequestException::class);

test('getReleases requests the native interactive search with the given params', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/release*' => Http::response([
            ['guid' => 'a', 'title' => 'Show S01E01'],
        ]),
    ]);

    $client = new SonarrClient($this->connection);
    $result = $client->getReleases(['seriesId' => 42, 'episodeId' => 101]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/release')
        && str_contains($request->url(), 'seriesId=42')
        && str_contains($request->url(), 'episodeId=101'));

    expect($result)->toHaveCount(1)
        ->and($result[0]['guid'])->toBe('a');
});

test('grabRelease posts the full release resource unchanged', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/release' => Http::response([], 201),
    ]);

    $release = ['guid' => 'abc', 'indexerId' => 3, 'title' => 'Show S01E01', 'downloadUrl' => 'http://x/y'];
    $client = new SonarrClient($this->connection);
    $client->grabRelease($release);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v3/release')
        && $request->data() === $release);
});

test('markHistoryFailed posts to the failed-history endpoint', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/history/failed/77' => Http::response([], 200),
    ]);

    $client = new SonarrClient($this->connection);
    $client->markHistoryFailed(77);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v3/history/failed/77'));
});

test('grabRelease is not retried on a server error (single non-idempotent POST)', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response([], 500)]);

    $client = new SonarrClient($this->connection);

    // A generic retry would issue this non-idempotent POST up to 3 times and
    // could start duplicate downloads; grabRelease opts out of the retry.
    try {
        $client->grabRelease(['guid' => 'abc', 'title' => 'X']);
    } catch (RequestException) {
        // expected — a 500 surfaces to the caller to classify
    }

    Http::assertSentCount(1);
});

test('a 200 read whose body is not JSON data is an upstream failure, not an empty result, and is not cached', function (string $service, string $method, array $arguments, string $path, string $body): void {
    $host = $service === 'sonarr' ? 'sonarr.local:8989' : 'radarr.local:7878';
    $connection = $service === 'sonarr'
        ? $this->connection
        : ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([$host.$path => Http::response($body, 200, ['Content-Type' => str_starts_with($body, '<') ? 'text/html' : 'application/json'])]);
    $client = $service === 'sonarr' ? new SonarrClient($connection) : new RadarrClient($connection);

    expect(fn (): array => $client->{$method}(...$arguments))
        ->toThrow(ArrUnexpectedResponse::class, sprintf('%s answered with a body that is not JSON data.', ucfirst($service)))
        ->and(fn (): array => $client->{$method}(...$arguments))
        ->toThrow(ArrUnexpectedResponse::class);

    // Both calls went upstream: the failure never entered the cache.
    Http::assertSentCount(2);
})->with([
    'sonarr series list, login page' => ['sonarr', 'getSeries', [], '/api/v3/series', '<html><body>Sign in</body></html>'],
    'sonarr series, scalar' => ['sonarr', 'getSeriesById', [42], '/api/v3/series/42', '42'],
    'sonarr episodes, login page' => ['sonarr', 'getEpisodesBySeries', [42], '/api/v3/episode*', '<html>Sign in</html>'],
    'sonarr lookup, json string' => ['sonarr', 'searchSeries', ['dune'], '/api/v3/series/lookup*', '"just a string"'],
    'sonarr quality profiles, login page' => ['sonarr', 'getQualityProfiles', [], '/api/v3/qualityprofile', '<html>Sign in</html>'],
    'sonarr root folders, scalar' => ['sonarr', 'getRootFolders', [], '/api/v3/rootfolder', 'true'],
    'sonarr queue, login page' => ['sonarr', 'getQueue', [[]], '/api/v3/queue*', '<html>Sign in</html>'],
    'sonarr history, login page' => ['sonarr', 'getHistory', [[]], '/api/v3/history*', '<html>Sign in</html>'],
    'sonarr manual import, scalar' => ['sonarr', 'getManualImport', [['downloadId' => 'd']], '/api/v3/manualimport*', '0'],
    'sonarr system status, login page' => ['sonarr', 'getSystemStatus', [], '/api/v3/system/status', '<html>Sign in</html>'],
    'radarr movies, login page' => ['radarr', 'getMovies', [], '/api/v3/movie', '<html>Sign in</html>'],
    'radarr movie, scalar' => ['radarr', 'getMovieById', [7], '/api/v3/movie/7', '42'],
    'radarr lookup, login page' => ['radarr', 'searchMovies', ['dune'], '/api/v3/movie/lookup*', '<html>Sign in</html>'],
    'radarr movie files, scalar' => ['radarr', 'getMovieFiles', [7], '/api/v3/moviefile*', '1'],
]);

test('the not-JSON failure is a RequestException on the 200 response and never quotes the body', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/series' => Http::response('<html>Sign in at /sso/login</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn (): array => new SonarrClient($this->connection)->getSeries())->toThrow(function (ArrUnexpectedResponse $arrUnexpectedResponse): void {
        expect($arrUnexpectedResponse)->toBeInstanceOf(RequestException::class)
            ->and($arrUnexpectedResponse->response->status())->toBe(200)
            ->and($arrUnexpectedResponse->getMessage())->toBe('Sonarr answered with a body that is not JSON data.');
    });
});

test('the fixed message survives report(), which would otherwise rebuild it from the raw body', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/series' => Http::response('<html>Sign in at /sso/login</html>', 200, ['Content-Type' => 'text/html'])]);

    try {
        new SonarrClient($this->connection)->getSeries();
    } catch (ArrUnexpectedResponse $arrUnexpectedResponse) {
        $arrUnexpectedResponse->report();

        expect($arrUnexpectedResponse->getMessage())->toBe('Sonarr answered with a body that is not JSON data.');

        return;
    }

    $this->fail('Expected ArrUnexpectedResponse to be thrown.');
});

test('an object-shaped list body still reads as data, as in Whisparr', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/qualityprofile' => Http::response(['message' => 'Unexpected'])]);

    expect(new SonarrClient($this->connection)->getQualityProfiles())->toBe(['message' => 'Unexpected']);
});
