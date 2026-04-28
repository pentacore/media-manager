<?php

declare(strict_types=1);

use App\Cache\Services\SeerrCache;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Seerr\SeerrWebhookHandler;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
});

test('handle() busts Seerr cache for the connection after processing', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create();
    $cache = new SeerrCache($connection);

    // Warm
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => 'test',
        'payload' => ['notification_type' => 'TEST_NOTIFICATION', 'message' => 'hi'],
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    // Cold — closure should run again
    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('handle() does not bust other connections cache', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create();
    $other = ServiceConnection::factory()->seerr()->create();

    $otherCache = new SeerrCache($other);
    $otherCache->rememberList('list', fn (): array => ['warm' => true]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => 'test',
        'payload' => ['notification_type' => 'TEST_NOTIFICATION', 'message' => 'hi'],
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $hits = 0;
    $otherCache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(0);
});
