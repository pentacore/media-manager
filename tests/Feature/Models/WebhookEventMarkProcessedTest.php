<?php

declare(strict_types=1);

use App\Enums\WebhookHandlingStatus;
use App\Events\WebhookEventProcessed;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;

test('markProcessed dispatches WebhookEventProcessed', function (): void {
    Event::fake([WebhookEventProcessed::class]);

    $webhookEvent = WebhookEvent::factory()->create(['processed_at' => null]);

    $webhookEvent->markProcessed(WebhookHandlingStatus::Handled);

    Event::assertDispatched(fn (WebhookEventProcessed $webhookEventProcessed): bool => $webhookEventProcessed->webhookEvent->id === $webhookEvent->id);
    expect($webhookEvent->fresh()->handling_status)->toBe(WebhookHandlingStatus::Handled)
        ->and($webhookEvent->fresh()->processed_at)->not->toBeNull();
});
