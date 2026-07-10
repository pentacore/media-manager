<?php

declare(strict_types=1);

use App\Enums\WebhookHandlingStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;

test('a handled event is persisted as Handled', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'payload' => ['eventType' => 'Test'],
    ]);

    new ProcessWebhookEvent($event)->handle();

    expect($event->refresh()->handling_status)->toBe(WebhookHandlingStatus::Handled);
});

test('an unmatched event is persisted as Ignored', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'payload' => ['eventType' => 'TotallyUnknown'],
    ]);

    new ProcessWebhookEvent($event)->handle();

    expect($event->refresh()->handling_status)->toBe(WebhookHandlingStatus::Ignored);
});

test('failed() marks the event as Failed', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);

    new ProcessWebhookEvent($event)->failed(new RuntimeException('boom'));

    expect($event->refresh()->handling_status)->toBe(WebhookHandlingStatus::Failed);
});
