<?php

declare(strict_types=1);

use App\Events\WebhookEventProcessed;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;

test('markProcessed dispatches WebhookEventProcessed', function (): void {
    Event::fake([WebhookEventProcessed::class]);

    $webhookEvent = WebhookEvent::factory()->create(['processed_at' => null]);

    $webhookEvent->markProcessed();

    Event::assertDispatched(fn (WebhookEventProcessed $webhookEventProcessed): bool => $webhookEventProcessed->webhookEvent->id === $webhookEvent->id);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});
