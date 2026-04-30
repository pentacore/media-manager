<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sabnzbd\SabnzbdWebhookHandler;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->sabnzbd()->create();
});

test('complete event writes ActivityLog and marks processed', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'complete',
        'payload' => [
            'eventType' => 'complete',
            'name' => 'Some.Show.S01E01',
            'category' => 'tv',
            'hostname' => 'sab.local',
            'version' => '4.5.0',
        ],
    ]);

    resolve(SabnzbdWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', 'webhook.sabnzbd.download.completed')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Some.Show.S01E01');
    expect($log->metadata['category'])->toBe('tv');
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('failed event writes ActivityLog with the upstream message', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'failed',
        'payload' => [
            'eventType' => 'failed',
            'name' => 'Broken.Release',
            'message' => 'Sample folder not allowed',
        ],
    ]);

    resolve(SabnzbdWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', 'webhook.sabnzbd.download.failed')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Sample folder not allowed');
});

test('disk_full event tags severity in metadata', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'disk_full',
        'payload' => [
            'eventType' => 'disk_full',
            'title' => 'Disk full',
            'message' => 'Less than 1 GB free',
        ],
    ]);

    resolve(SabnzbdWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', 'webhook.sabnzbd.alert.disk_full')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['severity'])->toBe('disk_full');
    expect($log->metadata['message'])->toBe('Less than 1 GB free');
});

test('warning and error events route through the alert path', function (): void {
    foreach (['warning', 'error'] as $level) {
        $webhookEvent = WebhookEvent::factory()->create([
            'service_connection_id' => $this->connection->id,
            'event_type' => $level,
            'payload' => [
                'eventType' => $level,
                'title' => ucfirst($level),
                'message' => 'Something happened',
            ],
        ]);

        resolve(SabnzbdWebhookHandler::class)->handle($webhookEvent);

        $log = ActivityLog::where('action', sprintf('webhook.sabnzbd.alert.%s', $level))->latest('id')->first();
        expect($log)->not->toBeNull();
        expect($log->metadata['severity'])->toBe($level);
    }
});

test('pause and resume events log without alert metadata', function (): void {
    foreach ([
        'pause' => 'webhook.sabnzbd.queue.paused',
        'resume' => 'webhook.sabnzbd.queue.resumed',
    ] as $eventType => $expectedAction) {
        $webhookEvent = WebhookEvent::factory()->create([
            'service_connection_id' => $this->connection->id,
            'event_type' => $eventType,
            'payload' => [
                'eventType' => $eventType,
            ],
        ]);

        resolve(SabnzbdWebhookHandler::class)->handle($webhookEvent);

        expect(ActivityLog::where('action', $expectedAction)->latest('id')->first())->not->toBeNull();
    }
});

test('unknown event types are ignored without writing ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'mystery',
        'payload' => ['eventType' => 'mystery'],
    ]);

    resolve(SabnzbdWebhookHandler::class)->handle($webhookEvent);

    expect(ActivityLog::where('action', 'like', 'webhook.sabnzbd.%')->count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});
