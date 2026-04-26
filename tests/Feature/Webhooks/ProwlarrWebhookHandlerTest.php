<?php

declare(strict_types=1);

use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Prowlarr\ProwlarrWebhookHandler;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->prowlarr()->create();

    $this->handler = resolve(ProwlarrWebhookHandler::class);
});

function makeProwlarrWebhookEvent(ServiceConnection $connection, string $eventType, array $extra = []): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => $eventType,
        'payload' => array_merge(['eventType' => $eventType, 'instanceName' => 'Prowlarr'], $extra),
    ]);
}

test('Test event writes an ActivityLog row and marks the webhook processed', function (): void {
    $event = makeProwlarrWebhookEvent($this->connection, 'Test', ['applicationUrl' => 'http://prowlarr.local']);

    $this->handler->handle($event);

    expect(ActivityLog::where('action', 'webhook.prowlarr.test')->count())->toBe(1);
    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('Health event writes an ActivityLog row and dispatches a health re-ping', function (): void {
    Bus::fake([PingServiceHealth::class]);

    $event = makeProwlarrWebhookEvent($this->connection, 'Health', [
        'level' => 'warning',
        'type' => 'IndexerLongTermStatusCheck',
        'message' => 'Indexer 1 has been failing for 12 hours.',
        'wikiUrl' => 'https://wiki.servarr.com/prowlarr/system/health',
    ]);

    $this->handler->handle($event);

    expect(ActivityLog::where('action', 'webhook.prowlarr.health')->count())->toBe(1);
    Bus::assertDispatched(PingServiceHealth::class);
});

test('HealthRestored event writes an ActivityLog row and dispatches a health re-ping', function (): void {
    Bus::fake([PingServiceHealth::class]);

    $event = makeProwlarrWebhookEvent($this->connection, 'HealthRestored', [
        'level' => 'ok',
        'type' => 'IndexerLongTermStatusCheck',
        'message' => 'Indexer 1 is healthy again.',
    ]);

    $this->handler->handle($event);

    expect(ActivityLog::where('action', 'webhook.prowlarr.health_restored')->count())->toBe(1);
    Bus::assertDispatched(PingServiceHealth::class);
});

test('ApplicationUpdate event writes an ActivityLog row and dispatches a version re-fetch', function (): void {
    Bus::fake([FetchLatestServiceVersion::class]);

    $event = makeProwlarrWebhookEvent($this->connection, 'ApplicationUpdate', [
        'previousVersion' => '1.20.0.4500',
        'newVersion' => '1.21.0.4600',
        'message' => 'Prowlarr was updated.',
    ]);

    $this->handler->handle($event);

    expect(ActivityLog::where('action', 'webhook.prowlarr.updated')->count())->toBe(1);
    Bus::assertDispatched(FetchLatestServiceVersion::class);
});

test('unknown event types are ignored without writing ActivityLog', function (): void {
    $event = makeProwlarrWebhookEvent($this->connection, 'NotARealEvent');

    $this->handler->handle($event);

    expect(ActivityLog::count())->toBe(0);
    expect($event->fresh()->processed_at)->not->toBeNull();
});
