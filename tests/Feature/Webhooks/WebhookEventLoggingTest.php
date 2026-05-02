<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

test('webhook stores event in database', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-token',
    ]);

    $payload = [
        'eventType' => 'Download',
        'series' => ['title' => 'Breaking Bad'],
        'episodes' => [['episodeNumber' => 1]],
    ];

    $this->postJson(
        '/api/webhooks/sonarr/'.$connection->id,
        $payload,
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'Download',
    ]);

    $event = WebhookEvent::first();
    expect($event->payload)->toMatchArray($payload);
    expect($event->processed_at)->not->toBeNull();
});

test('webhook stores event_type as unknown when payload has no recognized key', function (): void {
    $connection = ServiceConnection::factory()->emby()->create([
        'webhook_token' => 'test-token',
    ]);

    $this->postJson(
        '/api/webhooks/emby/'.$connection->id,
        ['some' => 'data'],
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'unknown',
    ]);
});

test('emby webhook stores event_type from PascalCase Event key', function (): void {
    $connection = ServiceConnection::factory()->emby()->create([
        'webhook_token' => 'test-token',
    ]);

    $this->postJson(
        '/api/webhooks/emby/'.$connection->id,
        ['Event' => 'playback.start', 'Item' => ['Type' => 'Episode']],
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'playback.start',
    ]);
});

test('seerr webhook stores event_type from snake_case notification_type key', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create([
        'webhook_token' => 'test-token',
    ]);

    $this->postJson(
        '/api/webhooks/seerr/'.$connection->id,
        ['notification_type' => 'MEDIA_PENDING'],
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'MEDIA_PENDING',
    ]);
});

test('prowlarr webhook stores event_type from camelCase eventType key', function (): void {
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'webhook_token' => 'test-token',
    ]);

    $this->postJson(
        '/api/webhooks/prowlarr/'.$connection->id,
        ['eventType' => 'Test'],
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'Test',
    ]);
});

test('sabnzbd webhook stores event_type from camelCase eventType key', function (): void {
    $connection = ServiceConnection::factory()->sabnzbd()->create([
        'webhook_token' => 'test-token',
    ]);

    $this->postJson(
        '/api/webhooks/sabnzbd/'.$connection->id,
        ['eventType' => 'complete'],
        ['X-Webhook-Token' => 'test-token']
    )->assertOk();

    $this->assertDatabaseHas('webhook_events', [
        'service_connection_id' => $connection->id,
        'event_type' => 'complete',
    ]);
});

test('emby duplicate playback.start payloads dedupe to single row', function (): void {
    Queue::fake();
    $connection = ServiceConnection::factory()->emby()->create([
        'webhook_token' => 'test-token',
    ]);
    $headers = ['X-Webhook-Token' => 'test-token'];
    $payload = ['Event' => 'playback.start', 'Item' => ['Id' => '123', 'Type' => 'Episode']];

    $this->postJson('/api/webhooks/emby/'.$connection->id, $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/emby/'.$connection->id, $payload, $headers)->assertOk();

    expect(WebhookEvent::count())->toBe(1);
    expect(WebhookEvent::first()->event_type)->toBe('playback.start');
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

test('emby distinct events create separate rows with correct event_type', function (): void {
    $connection = ServiceConnection::factory()->emby()->create([
        'webhook_token' => 'test-token',
    ]);
    $headers = ['X-Webhook-Token' => 'test-token'];

    $this->postJson(
        '/api/webhooks/emby/'.$connection->id,
        ['Event' => 'playback.start', 'Item' => ['Id' => '123']],
        $headers
    )->assertOk();
    $this->postJson(
        '/api/webhooks/emby/'.$connection->id,
        ['Event' => 'playback.pause', 'Item' => ['Id' => '123']],
        $headers
    )->assertOk();

    expect(WebhookEvent::count())->toBe(2);
    expect(WebhookEvent::orderBy('id')->pluck('event_type')->toArray())
        ->toBe(['playback.start', 'playback.pause']);
});

test('multiple webhooks create separate events', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'webhook_token' => 'test-token',
    ]);

    $headers = ['X-Webhook-Token' => 'test-token'];

    $this->postJson('/api/webhooks/radarr/'.$connection->id, ['eventType' => 'Grab'], $headers);
    $this->postJson('/api/webhooks/radarr/'.$connection->id, ['eventType' => 'Download'], $headers);

    expect(WebhookEvent::count())->toBe(2);
    expect(WebhookEvent::orderBy('id')->pluck('event_type')->toArray())->toBe(['Grab', 'Download']);
});

test('duplicate webhook deliveries are idempotent', function (): void {
    Queue::fake();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'test-token',
    ]);
    $headers = ['X-Webhook-Token' => 'test-token'];
    $payload = [
        'eventType' => 'Download',
        'series' => ['id' => 123, 'title' => 'Breaking Bad'],
    ];

    $this->postJson('/api/webhooks/sonarr/'.$connection->id, $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/sonarr/'.$connection->id, $payload, $headers)->assertOk();

    expect(WebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});
