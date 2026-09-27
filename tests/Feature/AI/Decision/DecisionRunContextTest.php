<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('eventDownloadId reads the top-level downloadId', function (): void {
    $context = new DecisionRunContext(null, 3, 'sonarr', ['downloadId' => 'dl-1']);

    expect($context->eventDownloadId())->toBe('dl-1');
});

test('eventDownloadId falls back to downloadInfo.downloadId', function (): void {
    $context = new DecisionRunContext(null, 3, 'sonarr', ['downloadInfo' => ['downloadId' => 'dl-2']]);

    expect($context->eventDownloadId())->toBe('dl-2');
});

test('eventDownloadId is null when neither field is present', function (): void {
    $context = new DecisionRunContext(null, 3, 'sonarr', ['eventType' => 'Grab']);

    expect($context->eventDownloadId())->toBeNull();
});

test('resolveConnection returns the pinned connection when an origin connection is known', function (): void {
    $first = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-a.local:8989']);
    $second = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-b.local:8989']);

    $context = new DecisionRunContext(null, 3, 'sonarr', [], $second->id);

    expect($context->resolveConnection(ServiceType::Sonarr)->id)->toBe($second->id);
});

test('resolveConnection falls back to the active connection when no origin connection is known', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    $context = new DecisionRunContext(null, 3, 'sonarr', []);

    expect($context->resolveConnection(ServiceType::Sonarr)->id)->toBe($connection->id);
});

test('resolveConnection throws when the pinned connection was deleted', function (): void {
    $context = new DecisionRunContext(null, 3, 'sonarr', [], 999999);

    $this->expectException(ModelNotFoundException::class);
    $context->resolveConnection(ServiceType::Sonarr);
});

test('proposalReason names the event type and origin connection when the event row is gone', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr 4K']);

    $context = new DecisionRunContext(
        webhookEventId: null,
        maxActions: 3,
        sourceService: 'sonarr',
        eventPayload: ['eventType' => 'ManualInteractionRequired'],
        originConnectionId: $connection->id,
        eventType: 'ManualInteractionRequired',
    );

    expect($context->proposalReason())
        ->toBe('Proposed by the decision agent in response to a "ManualInteractionRequired" event from Sonarr 4K.');
});

test('proposalReason falls back to sourceService when eventType is known but no origin connection is', function (): void {
    $context = new DecisionRunContext(
        webhookEventId: null,
        maxActions: 3,
        sourceService: 'sonarr',
        eventType: 'ManualInteractionRequired',
    );

    expect($context->proposalReason())
        ->toBe('Proposed by the decision agent in response to a "ManualInteractionRequired" event from sonarr.');
});

test('proposalReason still reads the persisted event row when eventType is not provided', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr']);
    $webhookEvent = WebhookEvent::factory()->for($connection, 'serviceConnection')->create(['event_type' => 'Grab']);

    $context = new DecisionRunContext(
        webhookEventId: $webhookEvent->id,
        maxActions: 3,
        sourceService: 'sonarr',
    );

    expect($context->proposalReason())
        ->toBe('Proposed by the decision agent in response to a "Grab" event from Sonarr.');
});

test('proposalReason is generic when neither eventType nor a persisted event row is available', function (): void {
    $context = new DecisionRunContext(null, 3, 'sonarr');

    expect($context->proposalReason())->toBe('Proposed by the decision agent.');
});
