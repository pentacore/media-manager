<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Events\ActionRequestStatusChanged;
use App\Events\DashboardStatsUpdated;
use App\Events\ServiceHealthChanged;
use App\Events\WebhookReceived;
use App\Jobs\BroadcastDashboardStats;
use App\Listeners\RebroadcastDashboardStats;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

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

test('snapshot returns the four counters', function (): void {
    $dashboardStatsService = resolve(DashboardStatsService::class);

    expect($dashboardStatsService->snapshot())->toHaveKeys([
        'activeServices', 'totalServices', 'recentWebhooks', 'pendingActions',
    ]);
});

test('a burst of upstream events schedules one delayed broadcast job', function (): void {
    Queue::fake([BroadcastDashboardStats::class]);
    $webhookEvent = WebhookEvent::factory()->create();
    $rebroadcastDashboardStats = resolve(RebroadcastDashboardStats::class);

    $rebroadcastDashboardStats->handle(new WebhookReceived($webhookEvent));
    $rebroadcastDashboardStats->handle(new WebhookReceived($webhookEvent));
    $rebroadcastDashboardStats->handle(new WebhookReceived($webhookEvent));

    Queue::assertPushed(BroadcastDashboardStats::class, 1);
    Queue::assertPushed(BroadcastDashboardStats::class, fn (BroadcastDashboardStats $job): bool => $job->delay !== null);
});

test('an event after the job started schedules a trailing broadcast', function (): void {
    Queue::fake([BroadcastDashboardStats::class]);
    Event::fake([DashboardStatsUpdated::class]);
    $webhookEvent = WebhookEvent::factory()->create();
    $rebroadcastDashboardStats = resolve(RebroadcastDashboardStats::class);

    $rebroadcastDashboardStats->handle(new WebhookReceived($webhookEvent));
    app()->call([new BroadcastDashboardStats, 'handle']);
    $rebroadcastDashboardStats->handle(new WebhookReceived($webhookEvent));

    Queue::assertPushed(BroadcastDashboardStats::class, 2);
    Event::assertDispatchedTimes(DashboardStatsUpdated::class, 1);
});

test('the job broadcasts the current snapshot', function (): void {
    Event::fake([DashboardStatsUpdated::class]);
    $connection = ServiceConnection::factory()->create();
    WebhookEvent::factory()->count(3)->create(['service_connection_id' => $connection->id]);
    $webhookEvent = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);
    ActionRequest::factory()->count(2)->create([
        'webhook_event_id' => $webhookEvent->id,
        'status' => ActionRequestStatus::Pending,
    ]);

    app()->call([new BroadcastDashboardStats, 'handle']);

    Event::assertDispatched(fn (DashboardStatsUpdated $dashboardStatsUpdated): bool => $dashboardStatsUpdated->pendingActions === 2
        && $dashboardStatsUpdated->totalServices === 1
        && $dashboardStatsUpdated->recentWebhooks === 4);
});

test('the listener runs no stats queries itself', function (): void {
    Queue::fake([BroadcastDashboardStats::class]);
    $webhookEvent = WebhookEvent::factory()->create();

    DB::enableQueryLog();
    resolve(RebroadcastDashboardStats::class)->handle(new WebhookReceived($webhookEvent));

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(mb_strtolower($query['query']), 'count(')))->toBeEmpty();
});
