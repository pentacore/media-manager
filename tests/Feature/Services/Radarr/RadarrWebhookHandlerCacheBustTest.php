<?php

declare(strict_types=1);

use App\Cache\Services\RadarrCache;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Library\InterventionCounter;
use App\Services\Radarr\RadarrWebhookHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Queue::fake();
    Notification::fake();
    $this->mock(InterventionCounter::class)->shouldReceive('recompute')->andReturn(0);
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);
});

function radarrCacheBustEvent(ServiceConnection $serviceConnection, string $eventType): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => $eventType,
        'payload' => [
            'eventType' => $eventType,
            'movie' => ['id' => 42, 'title' => 'My Movie'],
        ],
    ]);
}

function radarrCacheBustWarm(ServiceConnection $serviceConnection): void
{
    new RadarrCache($serviceConnection)->rememberList('list', fn (): array => ['warm' => true]);
}

/**
 * The connection's cached movie list, read without populating it.
 */
function radarrCacheBustCachedList(ServiceConnection $serviceConnection): mixed
{
    $prefix = sprintf('radarr:%d', $serviceConnection->id);

    return Cache::store('array')->tags([$prefix])->get(sprintf('%s:list', $prefix));
}

test('a library-changing Radarr event clears the connection cache', function (string $eventType): void {
    $serviceConnection = ServiceConnection::factory()->radarr()->create();
    radarrCacheBustWarm($serviceConnection);

    resolve(RadarrWebhookHandler::class)->handle(radarrCacheBustEvent($serviceConnection, $eventType));

    expect(radarrCacheBustCachedList($serviceConnection))->toBeNull();
})->with(['Download', 'Rename', 'MovieAdded', 'MovieDelete', 'MovieFileDelete']);

test('a Radarr event that changes nothing cached keeps the connection cache', function (string $eventType): void {
    $serviceConnection = ServiceConnection::factory()->radarr()->create();
    radarrCacheBustWarm($serviceConnection);

    resolve(RadarrWebhookHandler::class)->handle(radarrCacheBustEvent($serviceConnection, $eventType));

    expect(radarrCacheBustCachedList($serviceConnection))->toBe(['warm' => true]);
})->with(['Test', 'Grab', 'Health', 'HealthRestored', 'ApplicationUpdate', 'ManualInteractionRequired', 'SomethingNew']);

test('a library-changing Radarr event leaves other connections caches alone', function (): void {
    $serviceConnection = ServiceConnection::factory()->radarr()->create();
    $otherConnection = ServiceConnection::factory()->radarr()->create();
    radarrCacheBustWarm($otherConnection);

    resolve(RadarrWebhookHandler::class)->handle(radarrCacheBustEvent($serviceConnection, 'Download'));

    expect(radarrCacheBustCachedList($otherConnection))->toBe(['warm' => true]);
});
