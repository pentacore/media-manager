<?php

declare(strict_types=1);

use App\Cache\Services\SonarrCache;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Library\InterventionCounter;
use App\Services\Sonarr\SonarrWebhookHandler;
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
    // ManualInteractionRequired recomputes the intervention badge, which
    // would otherwise walk the factory's Sonarr host.
    $this->mock(InterventionCounter::class)->shouldReceive('recompute')->andReturn(0);
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);
});

function sonarrCacheBustEvent(ServiceConnection $serviceConnection, string $eventType): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => $eventType,
        'payload' => [
            'eventType' => $eventType,
            'series' => ['id' => 42, 'title' => 'My Show'],
            'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
        ],
    ]);
}

/**
 * Warms the connection's cached series list.
 */
function sonarrCacheBustWarm(ServiceConnection $serviceConnection): void
{
    new SonarrCache($serviceConnection)->rememberList('list', fn (): array => ['warm' => true]);
}

/**
 * The connection's cached series list, read without populating it.
 */
function sonarrCacheBustCachedList(ServiceConnection $serviceConnection): mixed
{
    $prefix = sprintf('sonarr:%d', $serviceConnection->id);

    return Cache::store('array')->tags([$prefix])->get(sprintf('%s:list', $prefix));
}

test('a library-changing Sonarr event clears the connection cache', function (string $eventType): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create();
    sonarrCacheBustWarm($serviceConnection);

    resolve(SonarrWebhookHandler::class)->handle(sonarrCacheBustEvent($serviceConnection, $eventType));

    expect(sonarrCacheBustCachedList($serviceConnection))->toBeNull();
})->with(['Download', 'Rename', 'SeriesAdd', 'SeriesDelete', 'EpisodeFileDelete']);

test('a Sonarr event that changes nothing cached keeps the connection cache', function (string $eventType): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create();
    sonarrCacheBustWarm($serviceConnection);

    resolve(SonarrWebhookHandler::class)->handle(sonarrCacheBustEvent($serviceConnection, $eventType));

    expect(sonarrCacheBustCachedList($serviceConnection))->toBe(['warm' => true]);
})->with(['Test', 'Grab', 'Health', 'HealthRestored', 'ApplicationUpdate', 'ManualInteractionRequired', 'SomethingNew']);

test('a library-changing Sonarr event leaves other connections caches alone', function (): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create();
    $otherConnection = ServiceConnection::factory()->sonarr()->create();
    sonarrCacheBustWarm($otherConnection);

    resolve(SonarrWebhookHandler::class)->handle(sonarrCacheBustEvent($serviceConnection, 'Download'));

    expect(sonarrCacheBustCachedList($otherConnection))->toBe(['warm' => true]);
});
