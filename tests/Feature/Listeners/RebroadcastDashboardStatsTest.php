<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Events\ActionRequestStatusChanged;
use App\Events\DashboardStatsUpdated;
use App\Events\ServiceHealthChanged;
use App\Events\WebhookReceived;
use App\Listeners\RebroadcastDashboardStats;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Cache::flush();
});

test('listener is registered for the four upstream events', function (): void {
    foreach ([
        WebhookReceived::class,
        ActionRequestCreated::class,
        ActionRequestStatusChanged::class,
        ServiceHealthChanged::class,
    ] as $upstream) {
        $listeners = Event::getListeners($upstream);
        expect($listeners)->not->toBeEmpty();
    }
});

test('handle broadcasts current snapshot', function (): void {
    Event::fake([DashboardStatsUpdated::class]);

    $connection = ServiceConnection::factory()->create();
    WebhookEvent::factory()->count(3)->create(['service_connection_id' => $connection->id]);
    $webhookEvent = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);
    ActionRequest::factory()->count(2)->create([
        'webhook_event_id' => $webhookEvent->id,
        'status' => ActionRequestStatus::Pending,
    ]);

    $listener = app(RebroadcastDashboardStats::class);
    $listener->handle(new WebhookReceived($webhookEvent));

    Event::assertDispatched(
        DashboardStatsUpdated::class,
        fn (DashboardStatsUpdated $event): bool => $event->pendingActions === 2
            && $event->totalServices === 1
            && $event->recentWebhooks === 4,
    );
});

test('handle is throttled to one broadcast per second', function (): void {
    Event::fake([DashboardStatsUpdated::class]);

    $listener = app(RebroadcastDashboardStats::class);
    $webhookEvent = WebhookEvent::factory()->create();

    // Three rapid-fire calls in the same second should produce one broadcast.
    $listener->handle(new WebhookReceived($webhookEvent));
    $listener->handle(new WebhookReceived($webhookEvent));
    $listener->handle(new WebhookReceived($webhookEvent));

    Event::assertDispatchedTimes(DashboardStatsUpdated::class, 1);
});

test('snapshot returns the four counters', function (): void {
    $service = app(DashboardStatsService::class);

    expect($service->snapshot())->toHaveKeys([
        'activeServices', 'totalServices', 'recentWebhooks', 'pendingActions',
    ]);
});
