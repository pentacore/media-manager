<?php

declare(strict_types=1);

use App\Jobs\FetchLatestServiceVersion;
use App\Models\ServiceConnection;
use App\Support\ServiceCheckBatch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Bus::fake();
});

test('dispatches a service-versions batch with one job per supported active connection', function (): void {
    ServiceConnection::factory()->sonarr()->create();
    ServiceConnection::factory()->radarr()->create();
    ServiceConnection::factory()->seerr()->create();
    ServiceConnection::factory()->sonarr()->inactive()->create();

    $this->artisan('services:check-versions')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $pendingBatch): bool => $pendingBatch->name === 'service-versions'
        && $pendingBatch->jobs->count() === 3
        && $pendingBatch->jobs->every(fn (object $job): bool => $job instanceof FetchLatestServiceVersion));
});

test('caches the dispatched batch id under the versions key', function (): void {
    ServiceConnection::factory()->sonarr()->create();

    $this->artisan('services:check-versions')->assertSuccessful();

    expect(Cache::get(ServiceCheckBatch::CACHE_KEY_VERSIONS))->not->toBeNull();
});

test('does nothing when no active connections have a known upstream repo', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create();

    $this->artisan('services:check-versions')->assertSuccessful();

    Bus::assertNothingBatched();
    expect(Cache::get(ServiceCheckBatch::CACHE_KEY_VERSIONS))->toBeNull();
});
