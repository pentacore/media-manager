<?php

declare(strict_types=1);

use App\Console\Commands\RefreshWantedCount;
use App\Models\ServiceConnection;
use App\Services\Library\WantedCounter;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::forget(WantedCounter::CACHE_KEY);
});

test('the command recomputes and caches the monitored missing total', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake([
        'sonarr.local:8989/api/v3/wanted/missing*' => Http::response(['totalRecords' => 5, 'records' => []]),
        'radarr.local:7878/api/v3/wanted/missing*' => Http::response(['totalRecords' => 2, 'records' => []]),
    ]);

    $this->artisan(RefreshWantedCount::class)
        ->expectsOutput('Wanted missing items: 7')
        ->assertSuccessful();

    expect(Cache::get(WantedCounter::CACHE_KEY))->toBe(7);
});

test('the command keeps the previous total when a service fails', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Cache::put(WantedCounter::CACHE_KEY, 9, WantedCounter::CACHE_TTL);
    Http::fake(['sonarr.local:8989/api/v3/wanted/missing*' => Http::response([], 503)]);

    $this->artisan(RefreshWantedCount::class)
        ->expectsOutput('Wanted missing items: 9')
        ->assertSuccessful();

    expect(Cache::get(WantedCounter::CACHE_KEY))->toBe(9);
});

test('the command is scheduled every five minutes with a ten minute overlap expiry', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'library:refresh-wanted-count'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});
