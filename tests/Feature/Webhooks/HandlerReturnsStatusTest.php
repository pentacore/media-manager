<?php

declare(strict_types=1);

use App\Enums\WebhookHandlingStatus;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sonarr\SonarrWebhookHandler;

test('a matched event returns Handled', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'payload' => ['eventType' => 'Test'],
    ]);

    $status = resolve(SonarrWebhookHandler::class)->handle($event);

    expect($status)->toBe(WebhookHandlingStatus::Handled);
});

test('an unmatched event returns Ignored', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'payload' => ['eventType' => 'SomethingUnknown'],
    ]);

    $status = resolve(SonarrWebhookHandler::class)->handle($event);

    expect($status)->toBe(WebhookHandlingStatus::Ignored);
});
