<?php

use App\Models\ServiceConnection;
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

test('markHistoryFailed is not retried on a server error (single non-idempotent POST)', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/history/failed/77' => Http::response([], 500)]);

    $client = new SonarrClient($this->connection);

    // Upstream blocklists AND starts an AutoRedownloadFailed search before it
    // answers, so this outlives the generic 10s timeout. Under the generic
    // retry that became three blocklist-and-search side effects, and the three
    // attempts plus backoff surfaced to the user as a ~31s connection timeout.
    try {
        $client->markHistoryFailed(77);
    } catch (RequestException) {
        // expected — a 500 surfaces to the caller to classify
    }

    Http::assertSentCount(1);
});

test('getManualImport is not retried on a server error (the scan is expensive upstream)', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([], 500)]);

    $client = new SonarrClient($this->connection);

    try {
        $client->getManualImport(['downloadId' => 'abc']);
    } catch (RequestException) {
        // expected — a 500 surfaces to the caller to classify
    }

    Http::assertSentCount(1);
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
