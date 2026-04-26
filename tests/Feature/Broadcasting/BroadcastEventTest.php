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
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\QueryException;

test('ActionRequestCreated broadcasts on each admin and member user channel', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->member()->create();
    User::factory()->create();

    $actionRequest = ActionRequest::factory()->create();
    $event = new ActionRequestCreated($actionRequest);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastAs())->toBe('ActionRequestCreated');

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(2);
    expect(collect($channels)->map(fn (PrivateChannel $privateChannel): string => $privateChannel->name)->all())
        ->toContain('private-App.Models.User.'.$admin->id)
        ->toContain('private-App.Models.User.'.$member->id);

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'type', 'source_service', 'target_service', 'status', 'requires_approval', 'created_at']);
    expect($payload['id'])->toBe($actionRequest->id);
});

test('ActionRequestStatusChanged broadcasts on each admin and member user channel', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->member()->create();
    User::factory()->create();

    $actionRequest = ActionRequest::factory()->completed()->create();
    $event = new ActionRequestStatusChanged($actionRequest);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastAs())->toBe('ActionRequestStatusChanged');

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(2);
    expect(collect($channels)->map(fn (PrivateChannel $privateChannel): string => $privateChannel->name)->all())
        ->toContain('private-App.Models.User.'.$admin->id);

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'status', 'result', 'updated_at']);
    expect($payload['status'])->toBe('completed');

    // Result payload is narrowed to safe fields — exception/message are stripped.
    expect($payload['result'])->toHaveKeys(['success', 'reason']);
    expect($payload['result'])->not->toHaveKey('message');
    expect($payload['result'])->not->toHaveKey('exception');
});

test('ActionRequestStatusChanged strips sensitive detail from broadcast payload', function (): void {
    User::factory()->admin()->create();

    $actionRequest = ActionRequest::factory()->create([
        'result' => [
            'success' => false,
            'reason' => 'execution_failed',
            'message' => 'SQLSTATE leaking /var/www secrets',
            'exception' => QueryException::class,
        ],
    ]);

    $event = new ActionRequestStatusChanged($actionRequest);
    $payload = $event->broadcastWith();

    expect($payload['result'])->toEqual([
        'success' => false,
        'reason' => 'execution_failed',
    ]);
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
    expect($event->broadcastAs())->toBe('ServiceHealthChanged');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'name', 'type', 'is_active', 'status', 'message', 'last_seen_at']);
    expect($payload['status'])->toBe('healthy');
});

test('EmbyPlaybackUpdated broadcasts on emby activity channel', function (): void {
    $activity = EmbyActivity::factory()->create();
    $activity->load('embyUserLink');

    $event = new EmbyPlaybackUpdated($activity);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('emby.activity'));
    expect($event->broadcastAs())->toBe('EmbyPlaybackUpdated');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'emby_user_link_id', 'media_type', 'media_title', 'series_title', 'action', 'play_position', 'duration_ticks', 'emby_username', 'updated_at']);
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
    expect($event->broadcastAs())->toBe('DashboardStatsUpdated');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['activeServices', 'totalServices', 'recentWebhooks', 'pendingActions', 'updatedAt']);
    expect($payload['activeServices'])->toBe(3);
});
