<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sonarr\SonarrWebhookHandler;

test('handler-written activity is linked to its webhook event', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'payload' => ['eventType' => 'Test', 'instanceName' => 'Sonarr'],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($event);

    $log = ActivityLog::where('action', 'webhook.sonarr.test')->first();
    expect($log)->not->toBeNull()
        ->and($log->webhook_event_id)->toBe($event->id);
});

test('deleting the event nulls the activity link instead of deleting the row', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);
    $log = ActivityLog::create([
        'service_connection_id' => $connection->id,
        'webhook_event_id' => $event->id,
        'action' => 'webhook.sonarr.test',
        'description' => 'x',
        'metadata' => [],
    ]);

    $event->delete();

    expect($log->refresh()->webhook_event_id)->toBeNull()
        ->and(ActivityLog::find($log->id))->not->toBeNull();
});
