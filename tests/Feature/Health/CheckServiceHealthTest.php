<?php

declare(strict_types=1);

use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Support\ServiceCheckBatch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Bus::fake();
});

test('dispatches a service-health batch with one job per active connection', function (): void {
    ServiceConnection::factory()->sonarr()->create();
    ServiceConnection::factory()->radarr()->create();
    ServiceConnection::factory()->emby()->inactive()->create();

    $this->artisan('services:check-health')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $pendingBatch): bool => $pendingBatch->name === 'service-health'
        && $pendingBatch->jobs->count() === 2
        && $pendingBatch->jobs->every(fn (object $job): bool => $job instanceof PingServiceHealth));
});

test('caches the dispatched batch id under the health key', function (): void {
    ServiceConnection::factory()->sonarr()->create();

    $this->artisan('services:check-health')->assertSuccessful();

    expect(Cache::get(ServiceCheckBatch::CACHE_KEY_HEALTH))->not->toBeNull();
});

test('does nothing when there are no active connections', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create();

    $this->artisan('services:check-health')->assertSuccessful();

    Bus::assertNothingBatched();
    expect(Cache::get(ServiceCheckBatch::CACHE_KEY_HEALTH))->toBeNull();
});
