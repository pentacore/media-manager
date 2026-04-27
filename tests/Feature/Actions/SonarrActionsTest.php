<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrActions;
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
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Demo Show', 'tvdbId' => 999001, 'year' => 2024],
        ]),
        'sonarr.local:8989/api/v3/series' => Http::response([
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
