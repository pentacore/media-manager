<?php

declare(strict_types=1);

use App\Cache\Services\RadarrCache;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrActions;
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

test('deleteMovie busts the Radarr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);

    Http::fake(['radarr.local:7878/api/v3/movie/42*' => Http::response(null, 200)]);

    $cache = new RadarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'delete_movie',
        'payload' => ['radarr_movie_id' => 42, 'delete_files' => false],
    ]);

    new RadarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('monitorMovie busts the Radarr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response(['id' => 42, 'title' => 'Demo', 'monitored' => true]),
    ]);

    $cache = new RadarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'monitor_movie',
        'payload' => ['movie_id' => 42, 'monitored' => false],
    ]);

    new RadarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('setMovieQualityProfile busts the Radarr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response(['id' => 42, 'title' => 'Demo', 'qualityProfileId' => 1]),
    ]);

    $cache = new RadarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'set_movie_quality_profile',
        'payload' => ['movie_id' => 42, 'quality_profile_id' => 7],
    ]);

    new RadarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('addMovie busts the Radarr cache after a successful HTTP write', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['title' => 'Demo Movie', 'tmdbId' => 999001, 'year' => 2024],
        ]),
        'radarr.local:7878/api/v3/movie' => Http::sequence()
            ->push(['id' => 123, 'title' => 'Demo Movie', 'tmdbId' => 999001]),
    ]);

    $cache = new RadarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'add_movie',
        'target_service' => 'radarr',
        'payload' => [
            'tmdb_id' => 999001,
            'quality_profile_id' => 1,
            'root_folder_path' => '/movies',
            'monitored' => true,
        ],
    ]);

    new RadarrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('failed HTTP write does NOT bust the Radarr cache', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);

    // 500 from Radarr — `throw()` will fire after retries, action throws.
    Http::fake(['radarr.local:7878/api/v3/movie/42*' => Http::response('boom', 500)]);

    $cache = new RadarrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'delete_movie',
        'payload' => ['radarr_movie_id' => 42, 'delete_files' => false],
    ]);

    try {
        new RadarrActions()->execute($actionRequest);
    } catch (Throwable) {
        // expected — Radarr returned 500
    }

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['stale-but-warm' => true];
    });

    expect($hits)->toBe(0);
});
