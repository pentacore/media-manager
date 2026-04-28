<?php

declare(strict_types=1);

use App\Cache\Services\SonarrCache;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sonarr\SonarrWebhookHandler;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
});

test('handle() busts Sonarr cache for the connection after processing', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $cache = new SonarrCache($connection);

    // Warm
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => 'test',
        'payload' => ['eventType' => 'Test'],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    // Cold — closure should run again
    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('handle() does not bust other connections cache', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $other = ServiceConnection::factory()->sonarr()->create();

    $otherCache = new SonarrCache($other);
    $otherCache->rememberList('list', fn (): array => ['warm' => true]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => 'test',
        'payload' => ['eventType' => 'Test'],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $hits = 0;
    $otherCache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(0);
});
