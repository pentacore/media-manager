<?php

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;

test('webhook stores event in database', function () {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-token',
    ]);

    $payload = [
        'eventType' => 'Download',
        'series' => ['title' => 'Breaking Bad'],
        'episodes' => [['episodeNumber' => 1]],
    ];

    $this->postJson(
        "/api/webhooks/sonarr/{$connection->id}",
        $payload,
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'Download',
    ]);

    $event = WebhookEvent::first();
    expect($event->payload)->toMatchArray($payload);
    expect($event->processed_at)->toBeNull();
});

test('webhook stores event with unknown type when eventType missing', function () {
    $connection = ServiceConnection::factory()->emby()->create([
        'webhook_token' => 'test-token',
    ]);

    $this->postJson(
        "/api/webhooks/emby/{$connection->id}",
        ['some' => 'data'],
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'unknown',
    ]);
});

test('multiple webhooks create separate events', function () {
    $connection = ServiceConnection::factory()->radarr()->create([
        'webhook_token' => 'test-token',
    ]);

    $headers = ['X-Webhook-Token' => 'test-token'];

    $this->postJson("/api/webhooks/radarr/{$connection->id}", ['eventType' => 'Grab'], $headers);
    $this->postJson("/api/webhooks/radarr/{$connection->id}", ['eventType' => 'Download'], $headers);

    expect(WebhookEvent::count())->toBe(2);
    expect(WebhookEvent::pluck('event_type')->toArray())->toBe(['Grab', 'Download']);
});
