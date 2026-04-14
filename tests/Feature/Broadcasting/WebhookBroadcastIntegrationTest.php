<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\DashboardStatsUpdated;
use App\Events\WebhookReceived;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

test('webhook controller dispatches WebhookReceived event', function (): void {
    Event::fake([WebhookReceived::class]);
    Queue::fake();

    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->postJson(route('webhooks.handle', ['service' => 'sonarr', 'connection' => $connection->id]), [
        'eventType' => 'grab',
        'data' => ['title' => 'Test Show'],
    ], [
        'X-Webhook-Token' => $connection->webhook_token,
    ])->assertOk();

    Event::assertDispatched(fn (WebhookReceived $webhookReceived): bool => $webhookReceived->webhookEvent->service_connection_id === $connection->id
        && $webhookReceived->webhookEvent->event_type === 'grab');

    Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $processWebhookEvent): bool => $processWebhookEvent->webhookEvent->service_connection_id === $connection->id);
});

test('broadcast dashboard stats command dispatches DashboardStatsUpdated event', function (): void {
    Event::fake([DashboardStatsUpdated::class]);

    ServiceConnection::factory()->count(2)->create(['is_active' => true]);
    ServiceConnection::factory()->inactive()->create();

    $this->artisan('dashboard:broadcast-stats')
        ->assertSuccessful();

    Event::assertDispatched(DashboardStatsUpdated::class);
});

test('broadcast dashboard stats command sends correct counts', function (): void {
    Event::fake([DashboardStatsUpdated::class]);

    $connection = ServiceConnection::factory()->create(['is_active' => true]);
    ServiceConnection::factory()->inactive()->create();
    WebhookEvent::factory()->count(3)->create([
        'service_connection_id' => $connection->id,
        'created_at' => now()->subHours(12),
    ]);
    WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'created_at' => now()->subDays(2),
    ]);
    $webhookForActions = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'created_at' => now()->subDays(3),
    ]);
    ActionRequest::factory()->count(2)->create([
        'status' => ActionRequestStatus::Pending,
        'webhook_event_id' => $webhookForActions->id,
    ]);

    $this->artisan('dashboard:broadcast-stats')
        ->assertSuccessful();

    Event::assertDispatched(fn (DashboardStatsUpdated $dashboardStatsUpdated): bool => $dashboardStatsUpdated->activeServices === 1
        && $dashboardStatsUpdated->totalServices === 2
        && $dashboardStatsUpdated->recentWebhooks === 3
        && $dashboardStatsUpdated->pendingActions === 2);
});
