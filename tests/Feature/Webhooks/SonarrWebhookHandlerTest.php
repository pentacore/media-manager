<?php

declare(strict_types=1);

use App\Jobs\ProcessWebhookEvent;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sonarr\SonarrWebhookHandler;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->sonarr()->create();
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);
});

test('Download event dispatches emby_library_scan', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'series' => ['id' => 42, 'title' => 'My Show'],
            'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $request = ActionRequest::first();
    expect($request)->not->toBeNull();
    expect($request->type)->toBe('emby_library_scan');
    expect($request->source_service)->toBe('sonarr');
    expect($request->target_service)->toBe('emby');
    expect($request->webhook_event_id)->toBe($webhookEvent->id);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('other events are ignored', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Test',
        'payload' => ['eventType' => 'Test'],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('ProcessWebhookEvent routes sonarr connections to SonarrWebhookHandler', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Download',
        'payload' => ['eventType' => 'Download', 'series' => ['id' => 42, 'title' => 'X']],
    ]);

    new ProcessWebhookEvent($webhookEvent)->handle();

    expect(ActionRequest::count())->toBe(1);
    expect(ActionRequest::first()->type)->toBe('emby_library_scan');
});
