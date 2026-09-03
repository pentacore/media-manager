<?php

declare(strict_types=1);

use App\Events\ActionRequestCreated;
use App\Events\ActionRequestStatusChanged;
use App\Events\ActivityLogCreated;
use App\Events\DashboardStatsUpdated;
use App\Events\EmbyPlaybackUpdated;
use App\Events\MediaReplacementAttemptChanged;
use App\Events\ServiceConnectionDeleted;
use App\Events\ServiceConnectionUpserted;
use App\Events\ServiceHealthChanged;
use App\Events\ServiceLatestVersionFetched;
use App\Events\WebhookEventProcessed;
use App\Events\WebhookReceived;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

test('ActionRequestCreated broadcasts on the shared members.actions channel', function (): void {
    // Three users present — no per-user fan-out: the event hits one channel
    // regardless of how many members exist.
    User::factory()->admin()->create();
    User::factory()->member()->create();
    User::factory()->create();

    $actionRequest = ActionRequest::factory()->create();
    $event = new ActionRequestCreated($actionRequest);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect($event->broadcastAs())->toBe('ActionRequestCreated');
    expect($event->broadcastOn())->toEqual(new PrivateChannel('members.actions'));

    $payload = $event->broadcastWith();
    // Mirrors ActionRequestResource so the frontend can upsert realtime rows
    // into the table without "undefined" rendering for payload/webhook_source/etc.
    expect($payload)->toHaveKeys([
        'id', 'type', 'source_service', 'target_service', 'status',
        'requires_approval', 'payload', 'result', 'approved_by',
        'webhook_source', 'created_at', 'updated_at',
    ]);
    expect($payload['id'])->toBe($actionRequest->id);
});

test('ActionRequestCreated strips sensitive detail from broadcast payload', function (): void {
    $actionRequest = ActionRequest::factory()->create([
        'result' => [
            'success' => false,
            'reason' => 'execution_failed',
            'message' => 'leaked path /var/www/secrets',
            'exception' => 'SomeException',
        ],
    ]);

    $payload = new ActionRequestCreated($actionRequest)->broadcastWith();

    expect($payload['result'])->toEqual([
        'success' => false,
        'reason' => 'execution_failed',
    ]);
});

test('ActionRequestCreated strips replacement target and candidate details from broadcasts', function (): void {
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'payload' => [
            'title' => str_repeat('T', 400),
            'detail' => str_repeat('D', 1_100),
            'service' => 'sonarr',
            'scope' => 'anime',
            'target' => [
                'episode_file_ids' => [501, 502],
                'private_path' => '/anime/private/Frieren.mkv',
            ],
            'candidate_fingerprint' => 'private-candidate-fingerprint',
            'candidate' => [
                'season_pack' => true,
                'download_url' => 'https://indexer.test/private-download',
            ],
            'required_languages' => ['eng'],
            'confidence' => 97,
            'matched_rules' => ['Trusted English'],
            'selection_mode' => 'automatic',
            'agent_rationale' => str_repeat('R', 1_100),
            'subtitle_case_id' => 42,
        ],
    ]);

    $payload = new ActionRequestCreated($actionRequest)->broadcastWith();
    $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($payload['payload'])->toMatchArray([
        'title' => str_repeat('T', 300),
        'detail' => str_repeat('D', 1_000),
        'affected_file_count' => 2,
        'season_pack' => true,
    ])
        ->and($encodedPayload)
        ->not->toContain('/anime/private/Frieren.mkv')
        ->not->toContain('private-candidate-fingerprint')
        ->not->toContain('https://indexer.test/private-download');
});

test('ActionRequestStatusChanged broadcasts on the shared members.actions channel', function (): void {
    User::factory()->admin()->create();
    User::factory()->member()->create();
    User::factory()->create();

    $actionRequest = ActionRequest::factory()->completed()->create();
    $event = new ActionRequestStatusChanged($actionRequest);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastAs())->toBe('ActionRequestStatusChanged');
    expect($event->broadcastOn())->toEqual(new PrivateChannel('members.actions'));

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
    expect($payload)->toHaveKeys(['id', 'emby_user_link_id', 'media_type', 'media_title', 'series_title', 'action', 'play_position', 'duration_ticks', 'emby_username', 'created_at', 'updated_at']);
});

test('ActivityLogCreated broadcasts on activity channel with full payload', function (): void {
    $user = User::factory()->create();
    $connection = ServiceConnection::factory()->create();
    $log = ActivityLog::factory()->create([
        'user_id' => $user->id,
        'service_connection_id' => $connection->id,
    ]);

    $event = new ActivityLogCreated($log);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('activity'));
    expect($event->broadcastAs())->toBe('ActivityLogCreated');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys([
        'id', 'action', 'description', 'user_name', 'service_id',
        'service_name', 'service_type', 'subject_type', 'subject_id',
        'metadata', 'created_at',
    ]);
    expect($payload['user_name'])->toBe($user->name);
    expect($payload['service_name'])->toBe($connection->name);
});

test('ServiceLatestVersionFetched broadcasts on services channel', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'version' => '4.0.4',
        'latest_version' => '4.0.5',
    ]);

    $event = new ServiceLatestVersionFetched($connection);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('services'));
    expect($event->broadcastAs())->toBe('ServiceLatestVersionFetched');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'name', 'type', 'version', 'latest_version', 'update_available']);
    expect($payload['update_available'])->toBeTrue();
});

test('ServiceConnectionUpserted broadcasts on services channel with full snapshot', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    $event = new ServiceConnectionUpserted($connection);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('services'));
    expect($event->broadcastAs())->toBe('ServiceConnectionUpserted');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys([
        'id', 'type', 'name', 'url', 'is_active', 'health_status',
        'health_message', 'version', 'latest_version', 'update_available',
        'last_seen_at',
    ]);
});

test('ServiceConnectionDeleted broadcasts on services channel with id only', function (): void {
    $event = new ServiceConnectionDeleted(serviceConnectionId: 42);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('services'));
    expect($event->broadcastAs())->toBe('ServiceConnectionDeleted');
    expect($event->broadcastWith())->toBe(['id' => 42]);
});

test('WebhookEventProcessed broadcasts on dashboard channel', function (): void {
    $webhookEvent = WebhookEvent::factory()->create(['processed_at' => now()]);

    $event = new WebhookEventProcessed($webhookEvent);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('dashboard'));
    expect($event->broadcastAs())->toBe('WebhookEventProcessed');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'processed_at']);
});

test('DashboardStatsUpdated broadcasts on dashboard channel via the queue', function (): void {
    $event = new DashboardStatsUpdated(
        activeServices: 3,
        totalServices: 5,
        healthyServices: 4,
        recentWebhooks: 12,
        pendingActions: 2,
        recentActions: 8,
        failedActions: 1,
    );

    // Must be queued, not ShouldBroadcastNow — a Reverb outage on the inline path
    // would bubble out of the synchronous webhook controller and 500 the request.
    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event)->not->toBeInstanceOf(ShouldBroadcastNow::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('dashboard'));
    expect($event->broadcastAs())->toBe('DashboardStatsUpdated');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys([
        'activeServices', 'totalServices', 'healthyServices',
        'recentWebhooks', 'pendingActions', 'recentActions', 'failedActions',
        'updatedAt',
    ]);
    expect($payload['activeServices'])->toBe(3);
    expect($payload['healthyServices'])->toBe(4);
    expect($payload['recentActions'])->toBe(8);
    expect($payload['failedActions'])->toBe(1);
});

test('webhook intake survives a broken broadcaster because dashboard rebroadcast is queued', function (): void {
    Queue::fake();

    $connection = ServiceConnection::factory()->sonarr()->create();

    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'invalid',
        'secret' => 'invalid',
        'app_id' => 'invalid',
        'options' => [
            'host' => '127.0.0.1',
            'port' => 1,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);

    $response = $this->postJson(
        route('webhooks.handle', ['service' => 'sonarr', 'connection' => $connection->id]),
        ['eventType' => 'grab', 'data' => ['title' => 'Some Show']],
        ['X-Webhook-Token' => $connection->webhook_token],
    );

    $response->assertOk();
});

test('MediaReplacementAttemptChanged broadcasts a scalar summary on the admin channel', function (): void {
    MediaReplacementAttempt::factory()->needsAttention()->create();
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->create();

    $event = new MediaReplacementAttemptChanged($attempt);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event)->not->toBeInstanceOf(ShouldBroadcastNow::class);
    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect($event->broadcastOn())->toEqual(new PrivateChannel('admin.media-replacement'));
    expect($event->broadcastAs())->toBe('MediaReplacementAttemptChanged');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys([
        'id', 'action_request_id', 'status', 'failure_reason', 'scope', 'service_type',
        'display_name', 'acknowledged', 'completed_at', 'updated_at', 'attention_unacknowledged',
    ]);
    expect($payload['status'])->toBe('needs_attention');
    expect($payload['service_type'])->toBe('sonarr');
    expect($payload['display_name'])->toBe('Trusted Anime S01E01');
    expect($payload['acknowledged'])->toBeFalse();
    expect($payload['attention_unacknowledged'])->toBe(2);
    // JSON columns never leave the server through the socket.
    expect($payload)->not->toHaveKeys(['target', 'candidate', 'verification']);
});
