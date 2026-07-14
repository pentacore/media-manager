<?php

use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test-api-key',
    ]);

    $this->client = new SonarrClient($this->connection);
});

test('getSeries returns array of series', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'title' => 'Breaking Bad', 'year' => 2008],
            ['id' => 2, 'title' => 'The Bear', 'year' => 2022],
        ]),
    ]);

    $result = $this->client->getSeries();

    expect($result)->toHaveCount(2);
    expect($result[0]['title'])->toBe('Breaking Bad');
});

test('getSeriesById returns single series', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42,
            'title' => 'Breaking Bad',
            'seasonCount' => 5,
        ]),
    ]);

    $result = $this->client->getSeriesById(42);

    expect($result['id'])->toBe(42);
    expect($result['title'])->toBe('Breaking Bad');
});

test('addSeries sends POST with data', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            'id' => 99,
            'title' => 'New Show',
        ]),
    ]);

    $data = ['title' => 'New Show', 'tvdbId' => 12345, 'qualityProfileId' => 1, 'rootFolderPath' => '/tv'];
    $result = $this->client->addSeries($data);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['title'] === 'New Show'
        && $request->data()['tvdbId'] === 12345);

    expect($result['id'])->toBe(99);
});

test('updateSeries sends PUT with data', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42,
            'title' => 'Breaking Bad',
            'monitored' => false,
        ]),
    ]);

    $result = $this->client->updateSeries(42, ['monitored' => false]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data()['monitored'] === false);

    expect($result['monitored'])->toBeFalse();
});

test('deleteSeries sends DELETE without deleteFiles by default', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/*' => Http::response([], 200),
    ]);

    $this->client->deleteSeries(42);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/series/42')
        && str_contains($request->url(), 'deleteFiles=false'));
});

test('deleteSeries with deleteFiles sends correct query param', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/*' => Http::response([], 200),
    ]);

    $this->client->deleteSeries(42, deleteFiles: true);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'deleteFiles=true'));
});

test('searchSeries encodes query and returns results', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Breaking Bad', 'tvdbId' => 81189],
        ]),
    ]);

    $result = $this->client->searchSeries('breaking bad');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'term=breaking'));

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Breaking Bad');
});

test('getEpisodeFiles requests the series episode files', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/episodefile*' => Http::response([
            ['id' => 501, 'seriesId' => 42],
        ]),
    ]);

    $result = $this->client->getEpisodeFiles(42);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/episodefile')
        && str_contains($request->url(), 'seriesId=42'));

    expect($result)->toHaveCount(1)
        ->and($result[0]['id'])->toBe(501);
});

test('getEpisodeFileById requests a single episode file', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response(['id' => 501, 'seriesId' => 42]),
    ]);

    $result = $this->client->getEpisodeFileById(501);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/episodefile/501'));

    expect($result['id'])->toBe(501);
});

test('deleteEpisodeFile sends DELETE to the episode file', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([], 200),
    ]);

    $this->client->deleteEpisodeFile(501);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/episodefile/501'));
});

test('setEpisodesMonitored PUTs the episode monitor toggle', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 200),
    ]);

    $this->client->setEpisodesMonitored([101, 102], false);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['episodeIds'] === [101, 102]
        && $request->data()['monitored'] === false);
});
