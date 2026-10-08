<?php

declare(strict_types=1);

use App\Cache\Services\SeerrCache;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Seerr\SeerrWebhookHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Queue::fake();
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);
});

function seerrCacheBustEvent(ServiceConnection $serviceConnection, string $notificationType): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => $notificationType,
        'payload' => [
            'notification_type' => $notificationType,
            'subject' => 'Dune',
            'message' => 'hi',
            'media' => ['media_type' => 'movie', 'tmdbId' => '438631'],
            'request' => ['request_id' => '7', 'requestedBy_username' => 'alice'],
        ],
    ]);
}

function seerrCacheBustWarm(ServiceConnection $serviceConnection): void
{
    new SeerrCache($serviceConnection)->rememberList('list', fn (): array => ['warm' => true]);
}

/**
 * The connection's cached list entry, read without populating it.
 */
function seerrCacheBustCachedList(ServiceConnection $serviceConnection): mixed
{
    $prefix = sprintf('seerr:%d', $serviceConnection->id);

    return Cache::store('array')->tags([$prefix])->get(sprintf('%s:list', $prefix));
}

test('a request lifecycle notification clears the Seerr connection cache', function (string $notificationType): void {
    $serviceConnection = ServiceConnection::factory()->seerr()->create();
    seerrCacheBustWarm($serviceConnection);

    resolve(SeerrWebhookHandler::class)->handle(seerrCacheBustEvent($serviceConnection, $notificationType));

    expect(seerrCacheBustCachedList($serviceConnection))->toBeNull();
})->with(['MEDIA_PENDING', 'MEDIA_APPROVED', 'MEDIA_AUTO_APPROVED', 'MEDIA_DECLINED', 'MEDIA_AVAILABLE', 'MEDIA_FAILED']);

test('a Seerr notification that changes nothing cached keeps the connection cache', function (string $notificationType): void {
    $serviceConnection = ServiceConnection::factory()->seerr()->create();
    seerrCacheBustWarm($serviceConnection);

    resolve(SeerrWebhookHandler::class)->handle(seerrCacheBustEvent($serviceConnection, $notificationType));

    expect(seerrCacheBustCachedList($serviceConnection))->toBe(['warm' => true]);
})->with(['TEST_NOTIFICATION', 'ISSUE_CREATED', 'ISSUE_COMMENT', 'ISSUE_RESOLVED', 'ISSUE_REOPENED', 'SOMETHING_NEW']);

test('a request lifecycle notification leaves other connections caches alone', function (): void {
    $serviceConnection = ServiceConnection::factory()->seerr()->create();
    $otherConnection = ServiceConnection::factory()->seerr()->create();
    seerrCacheBustWarm($otherConnection);

    resolve(SeerrWebhookHandler::class)->handle(seerrCacheBustEvent($serviceConnection, 'MEDIA_APPROVED'));

    expect(seerrCacheBustCachedList($otherConnection))->toBe(['warm' => true]);
});
