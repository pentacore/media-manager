<?php

declare(strict_types=1);

use App\Events\LibraryInterventionChanged;
use App\Models\ServiceConnection;
use App\Services\Library\InterventionCounter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::forget(InterventionCounter::CACHE_KEY);
});

test('counts queue rows in warning/error/blocked states across both services', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.fake:8989',
    ]);
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.fake:7878',
    ]);

    Http::fake([
        'sonarr.fake:8989/api/v3/queue*' => Http::response([
            'records' => [
                ['trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading'],
                ['trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'importBlocked'],
                ['trackedDownloadStatus' => 'error', 'trackedDownloadState' => 'importFailed'],
            ],
        ]),
        'radarr.fake:7878/api/v3/queue*' => Http::response([
            'records' => [
                ['trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'importPending'],
                ['trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading'],
            ],
        ]),
    ]);

    Event::fake([LibraryInterventionChanged::class]);

    $count = resolve(InterventionCounter::class)->recompute();

    expect($count)->toBe(3);
    expect(Cache::get(InterventionCounter::CACHE_KEY))->toBe(3);

    Event::assertDispatched(fn (LibraryInterventionChanged $libraryInterventionChanged): bool => $libraryInterventionChanged->count === 3);
});

test('count is zero with no Sonarr or Radarr connection', function (): void {
    Event::fake([LibraryInterventionChanged::class]);

    $count = resolve(InterventionCounter::class)->recompute();

    expect($count)->toBe(0);
});

test('upstream HTTP failure does not zero the badge for the other service', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.fake:8989',
    ]);
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.fake:7878',
    ]);

    Http::fake([
        'sonarr.fake:8989/api/v3/queue*' => Http::response('Server Error', 500),
        'radarr.fake:7878/api/v3/queue*' => Http::response([
            'records' => [
                ['trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'importBlocked'],
            ],
        ]),
    ]);

    expect(resolve(InterventionCounter::class)->recompute())->toBe(1);
});

test('cold-cache recompute with a failing upstream still writes a cache entry', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.fake:8989',
    ]);

    Http::fake([
        'sonarr.fake:8989/api/v3/queue*' => Http::response('Server Error', 500),
    ]);

    expect(resolve(InterventionCounter::class)->recompute())->toBe(0);

    // Without this write, every Inertia render re-walks the unreachable
    // service inline (HandleInertiaRequests recomputes on cache miss),
    // stalling all page loads until the upstream answers again.
    expect(Cache::has(InterventionCounter::CACHE_KEY))->toBeTrue();
});

test('a failing upstream keeps the previously cached total instead of overwriting it', function (): void {
    Cache::put(InterventionCounter::CACHE_KEY, 7, 60);

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.fake:8989',
    ]);

    Http::fake([
        'sonarr.fake:8989/api/v3/queue*' => Http::response('Server Error', 500),
    ]);

    expect(resolve(InterventionCounter::class)->recompute())->toBe(7);
    expect(Cache::get(InterventionCounter::CACHE_KEY))->toBe(7);
});

test('get returns 0 when the cache is empty', function (): void {
    expect(resolve(InterventionCounter::class)->get())->toBe(0);
});

test('get returns the cached value', function (): void {
    Cache::put(InterventionCounter::CACHE_KEY, 7, 60);

    expect(resolve(InterventionCounter::class)->get())->toBe(7);
});
