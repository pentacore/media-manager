<?php

declare(strict_types=1);

use App\Cache\Services\WhisparrCache;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Library\InterventionCounter;
use App\Services\Whisparr\WhisparrWebhookHandler;
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
});

function whisparrCacheBustEvent(ServiceConnection $serviceConnection, string $eventType): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => $eventType,
        'payload' => [
            'eventType' => $eventType,
            'movie' => ['id' => 11, 'title' => 'Aurora Scene'],
        ],
    ]);
}

function whisparrCacheBustWarm(ServiceConnection $serviceConnection): void
{
    new WhisparrCache($serviceConnection)->rememberList('list', fn (): array => ['warm' => true]);
}

/**
 * The connection's cached library list, read without populating it.
 */
function whisparrCacheBustCachedList(ServiceConnection $serviceConnection): mixed
{
    $prefix = sprintf('whisparr:%d', $serviceConnection->id);

    return Cache::store('array')->tags([$prefix])->get(sprintf('%s:list', $prefix));
}

test('a library-changing Whisparr event clears the connection cache', function (string $eventType): void {
    $serviceConnection = ServiceConnection::factory()->whisparr()->create();
    whisparrCacheBustWarm($serviceConnection);

    resolve(WhisparrWebhookHandler::class)->handle(whisparrCacheBustEvent($serviceConnection, $eventType));

    expect(whisparrCacheBustCachedList($serviceConnection))->toBeNull();
})->with(['Download', 'Rename', 'MovieAdded', 'SeriesAdd', 'MovieDelete', 'SeriesDelete', 'MovieFileDelete', 'EpisodeFileDelete']);

test('a Whisparr event that changes nothing cached keeps the connection cache', function (string $eventType): void {
    $serviceConnection = ServiceConnection::factory()->whisparr()->create();
    whisparrCacheBustWarm($serviceConnection);

    resolve(WhisparrWebhookHandler::class)->handle(whisparrCacheBustEvent($serviceConnection, $eventType));

    expect(whisparrCacheBustCachedList($serviceConnection))->toBe(['warm' => true]);
})->with(['Test', 'Grab', 'Health', 'HealthRestored', 'ApplicationUpdate', 'ManualInteractionRequired', 'SomethingNew']);
