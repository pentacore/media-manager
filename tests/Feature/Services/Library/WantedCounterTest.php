<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Library\WantedCounter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::forget(WantedCounter::CACHE_KEY);
});

test('it sums the monitored missing totals of Sonarr and Radarr and caches them', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake([
        'sonarr.local:8989/api/v3/wanted/missing*' => Http::response(['totalRecords' => 12, 'records' => []]),
        'radarr.local:7878/api/v3/wanted/missing*' => Http::response(['totalRecords' => 3, 'records' => []]),
    ]);

    expect(resolve(WantedCounter::class)->recompute())->toBe(15)
        ->and(resolve(WantedCounter::class)->get())->toBe(15);
});

test('a failing service keeps the previous total instead of shrinking the badge', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Cache::put(WantedCounter::CACHE_KEY, 9, 600);
    Http::fake(['sonarr.local:8989/*' => Http::response([], 503)]);

    expect(resolve(WantedCounter::class)->recompute())->toBe(9);
});
