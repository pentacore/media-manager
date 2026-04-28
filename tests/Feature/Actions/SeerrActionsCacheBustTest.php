<?php

declare(strict_types=1);

use App\Cache\Services\SeerrCache;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrActions;
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

test('cleanup_seerr_request busts the Seerr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]);

    Http::fake(['seerr.local:5055/api/v1/request/55' => Http::response(null, 200)]);

    $cache = new SeerrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'cleanup_seerr_request',
        'payload' => ['seerr_request_id' => 55],
    ]);

    new SeerrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('approve_seerr_request busts the Seerr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]);

    Http::fake(['seerr.local:5055/api/v1/request/77/approve' => Http::response([], 200)]);

    $cache = new SeerrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'approve_seerr_request',
        'payload' => ['seerr_request_id' => 77],
    ]);

    new SeerrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('decline_seerr_request busts the Seerr cache for its connection', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]);

    Http::fake(['seerr.local:5055/api/v1/request/88/decline' => Http::response([], 200)]);

    $cache = new SeerrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'decline_seerr_request',
        'payload' => ['seerr_request_id' => 88],
    ]);

    new SeerrActions()->execute($actionRequest);

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('failed HTTP write does NOT bust the Seerr cache', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]);

    // 500 from Seerr — `throw()` will fire after retries, action throws.
    Http::fake(['seerr.local:5055/api/v1/request/55' => Http::response('boom', 500)]);

    $cache = new SeerrCache($connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $actionRequest = ActionRequest::factory()->create([
        'type' => 'cleanup_seerr_request',
        'payload' => ['seerr_request_id' => 55],
    ]);

    try {
        new SeerrActions()->execute($actionRequest);
    } catch (Throwable) {
        // expected — Seerr returned 500
    }

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['stale-but-warm' => true];
    });

    expect($hits)->toBe(0);
});
