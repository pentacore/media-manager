<?php

declare(strict_types=1);

use App\Cache\Services\SonarrCache;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Http::preventStrayRequests();
});

test('deleteSeries busts the Sonarr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    Http::fake(['sonarr.local:8989/api/v3/series/42*' => Http::response(null, 200)]);

    $cache = new SonarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => ['sonarr_series_id' => 42, 'delete_files' => false],
    ]);

    new SonarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('monitorSeries busts the Sonarr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Demo', 'monitored' => true]),
    ]);

    $cache = new SonarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'monitor_series',
        'payload' => ['series_id' => 42, 'monitored' => false],
    ]);

    new SonarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('setSeriesQualityProfile busts the Sonarr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Demo', 'qualityProfileId' => 1]),
    ]);

    $cache = new SonarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'set_series_quality_profile',
        'payload' => ['series_id' => 42, 'quality_profile_id' => 7],
    ]);

    new SonarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('addSeries busts the Sonarr cache after a successful HTTP write', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['title' => 'Demo Show', 'tvdbId' => 999001, 'year' => 2024],
        ]),
        'sonarr.local:8989/api/v3/series' => Http::sequence()
            ->push(['id' => 123, 'title' => 'Demo Show', 'tvdbId' => 999001]),
    ]);

    $cache = new SonarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

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

    new SonarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('failed HTTP write does NOT bust the Sonarr cache', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    // 500 from Sonarr — `throw()` will fire after retries, action throws.
    Http::fake(['sonarr.local:8989/api/v3/series/42*' => Http::response('boom', 500)]);

    $cache = new SonarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => ['sonarr_series_id' => 42, 'delete_files' => false],
    ]);

    try {
        new SonarrActions()->execute($actionRequest);
    } catch (Throwable) {
        // expected — Sonarr returned 500
    }

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['stale-but-warm' => true];
    });

    expect($hits)->toBe(0);
});
