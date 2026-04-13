<?php

declare(strict_types=1);

use App\Events\ActionRequestCreated;
use App\Events\ActionRequestStatusChanged;
use App\Events\DashboardStatsUpdated;
use App\Events\EmbyPlaybackUpdated;
use App\Events\ServiceHealthChanged;
use App\Events\WebhookReceived;
use App\Models\ActionRequest;
use App\Models\EmbyActivity;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

test('ActionRequestCreated broadcasts on dashboard channel with correct payload', function (): void {
    $actionRequest = ActionRequest::factory()->create();

    $event = new ActionRequestCreated($actionRequest);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('dashboard'));

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'type', 'source_service', 'target_service', 'status', 'requires_approval', 'created_at']);
    expect($payload['id'])->toBe($actionRequest->id);
});

test('ActionRequestStatusChanged broadcasts on dashboard channel with correct payload', function (): void {
    $actionRequest = ActionRequest::factory()->completed()->create();

    $event = new ActionRequestStatusChanged($actionRequest);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('dashboard'));

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'status', 'result', 'updated_at']);
    expect($payload['status'])->toBe('completed');
});

test('WebhookReceived broadcasts on dashboard channel with service info', function (): void {
    $webhookEvent = WebhookEvent::factory()->create();
    $webhookEvent->load('serviceConnection');

    $event = new WebhookReceived($webhookEvent);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('dashboard'));

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'service_connection_id', 'service_name', 'service_type', 'event_type', 'created_at']);
});

test('ServiceHealthChanged broadcasts on services channel with status', function (): void {
    $connection = ServiceConnection::factory()->create();

    $event = new ServiceHealthChanged($connection, 'healthy');

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('services'));

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'name', 'type', 'is_active', 'status', 'last_seen_at']);
    expect($payload['status'])->toBe('healthy');
});

test('EmbyPlaybackUpdated broadcasts on emby activity channel', function (): void {
    $activity = EmbyActivity::factory()->create();
    $activity->load('embyUserLink');

    $event = new EmbyPlaybackUpdated($activity);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('emby.activity'));

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'media_type', 'media_title', 'series_title', 'action', 'emby_username', 'updated_at']);
});

test('DashboardStatsUpdated broadcasts immediately on dashboard channel', function (): void {
    $event = new DashboardStatsUpdated(
        activeServices: 3,
        totalServices: 5,
        recentWebhooks: 12,
        pendingActions: 2,
    );

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('dashboard'));

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['activeServices', 'totalServices', 'recentWebhooks', 'pendingActions', 'updatedAt']);
    expect($payload['activeServices'])->toBe(3);
});
