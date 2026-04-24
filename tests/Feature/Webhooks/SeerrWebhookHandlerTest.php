<?php

declare(strict_types=1);

use App\Jobs\ProcessWebhookEvent;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Seerr\SeerrWebhookHandler;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->seerr()->create();
});

function seerrPayload(string $notificationType, array $overrides = []): array
{
    return array_replace_recursive([
        'notification_type' => $notificationType,
        'event' => 'New Movie Request',
        'subject' => 'The Matrix (1999)',
        'message' => 'A new request has been submitted.',
        'image' => null,
        'media' => [
            'media_type' => 'movie',
            'tmdbId' => '603',
            'tvdbId' => '0',
            'status' => 'PENDING',
        ],
        'request' => [
            'request_id' => '42',
            'requestedBy_username' => 'alice',
            'requestedBy_email' => 'alice@example.com',
        ],
        'issue' => null,
        'comment' => null,
    ], $overrides);
}

test('TEST_NOTIFICATION writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'TEST_NOTIFICATION',
        'payload' => [
            'notification_type' => 'TEST_NOTIFICATION',
            'subject' => 'Test',
            'message' => 'Seerr test notification.',
        ],
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.test',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('MEDIA_PENDING writes ActivityLog with requester info', function (): void {
    $payload = seerrPayload('MEDIA_PENDING');

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MEDIA_PENDING',
        'payload' => $payload,
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.request_pending',
    ]);

    $log = ActivityLog::where('action', 'webhook.seerr.request_pending')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('alice');
    expect($log->description)->toContain('The Matrix (1999)');
    expect($log->subject_id)->toBe(42);
    expect($log->metadata['media_type'])->toBe('movie');
    expect($log->metadata['tmdb_id'])->toBe('603');
    expect($log->metadata['requester'])->toBe('alice');
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('MEDIA_APPROVED writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MEDIA_APPROVED',
        'payload' => seerrPayload('MEDIA_APPROVED'),
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.request_approved',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('MEDIA_AUTO_APPROVED writes ActivityLog with request_approved action', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MEDIA_AUTO_APPROVED',
        'payload' => seerrPayload('MEDIA_AUTO_APPROVED'),
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.request_approved',
    ]);

    $log = ActivityLog::where('action', 'webhook.seerr.request_approved')->first();
    expect($log->description)->toContain('auto-approved');
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('MEDIA_DECLINED writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MEDIA_DECLINED',
        'payload' => seerrPayload('MEDIA_DECLINED'),
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.request_declined',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('MEDIA_AVAILABLE writes ActivityLog and dispatches emby_library_scan', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MEDIA_AVAILABLE',
        'payload' => seerrPayload('MEDIA_AVAILABLE'),
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.media_available',
    ]);

    $request = ActionRequest::first();
    expect($request)->not->toBeNull();
    expect($request->type)->toBe('emby_library_scan');
    expect($request->source_service)->toBe('seerr');
    expect($request->target_service)->toBe('emby');
    expect($request->webhook_event_id)->toBe($webhookEvent->id);
    expect($request->payload['trigger'])->toBe('seerr_media_available');
    expect($request->payload['subject'])->toBe('The Matrix (1999)');
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('MEDIA_FAILED writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MEDIA_FAILED',
        'payload' => seerrPayload('MEDIA_FAILED'),
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.request_failed',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('ISSUE_CREATED writes ActivityLog with issue metadata', function (): void {
    $payload = seerrPayload('ISSUE_CREATED', [
        'subject' => 'Bad audio on The Matrix',
        'issue' => [
            'issue_id' => '7',
            'issue_type' => 'AUDIO',
            'issue_status' => 'OPEN',
            'reportedBy_username' => 'alice',
        ],
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ISSUE_CREATED',
        'payload' => $payload,
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.issue_created',
    ]);

    $log = ActivityLog::where('action', 'webhook.seerr.issue_created')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['issue']['issue_id'])->toBe('7');
    expect($log->metadata['issue']['issue_type'])->toBe('AUDIO');
    expect($log->metadata['reporter'])->toBe('alice');
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('ISSUE_COMMENT writes ActivityLog', function (): void {
    $payload = seerrPayload('ISSUE_COMMENT', [
        'comment' => [
            'comment_message' => 'Still broken.',
            'commentedBy_username' => 'alice',
        ],
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ISSUE_COMMENT',
        'payload' => $payload,
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.issue_comment',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('ISSUE_RESOLVED writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ISSUE_RESOLVED',
        'payload' => seerrPayload('ISSUE_RESOLVED'),
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.issue_resolved',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('ISSUE_REOPENED writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ISSUE_REOPENED',
        'payload' => seerrPayload('ISSUE_REOPENED'),
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.issue_reopened',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('unknown notification_type is logged and skipped (no ActivityLog)', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'UNKNOWN',
        'payload' => ['notification_type' => 'SOMETHING_NEW'],
    ]);

    resolve(SeerrWebhookHandler::class)->handle($webhookEvent);

    expect(ActivityLog::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('ProcessWebhookEvent routes seerr connections to SeerrWebhookHandler', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'TEST_NOTIFICATION',
        'payload' => [
            'notification_type' => 'TEST_NOTIFICATION',
            'subject' => 'Test',
            'message' => 'Seerr test notification.',
        ],
    ]);

    new ProcessWebhookEvent($webhookEvent)->handle();

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.seerr.test',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});
