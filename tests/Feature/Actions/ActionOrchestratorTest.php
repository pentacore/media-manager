<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestCreated::class]);
    Queue::fake();
});

test('dispatch creates a Pending ActionRequest for requires_approval types', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    $request = resolve(ActionOrchestrator::class)->dispatch(
        type: 'delete_series',
        sourceService: 'emby',
        targetService: 'sonarr',
        payload: ['sonarr_series_id' => 42],
    );

    expect($request)->not->toBeNull();
    expect($request->status)->toBe(ActionRequestStatus::Pending);
    expect($request->requires_approval)->toBeTrue();
    expect($request->payload)->toBe(['sonarr_series_id' => 42]);

    Event::assertDispatched(ActionRequestCreated::class);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('dispatch auto-executes when config has requires_approval=false', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    $request = resolve(ActionOrchestrator::class)->dispatch(
        type: 'emby_library_scan',
        sourceService: 'sonarr',
        targetService: 'emby',
        payload: [],
    );

    expect($request->status)->toBe(ActionRequestStatus::Approved);
    expect($request->requires_approval)->toBeFalse();

    Queue::assertPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $executeActionRequest): bool => $executeActionRequest->actionRequest->id === $request->id);
});

test('dispatch returns null when config is missing', function (): void {
    $request = resolve(ActionOrchestrator::class)->dispatch(
        type: 'never_registered',
        sourceService: 'emby',
        targetService: 'sonarr',
        payload: [],
    );

    expect($request)->toBeNull();
    Event::assertNotDispatched(ActionRequestCreated::class);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('dispatch returns null when config is disabled', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'requires_approval' => true,
        'is_enabled' => false,
    ]);

    $request = resolve(ActionOrchestrator::class)->dispatch(
        type: 'delete_series',
        sourceService: 'emby',
        targetService: 'sonarr',
        payload: [],
    );

    expect($request)->toBeNull();
    Event::assertNotDispatched(ActionRequestCreated::class);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('dispatch links to source WebhookEvent when provided', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    $webhookEvent = WebhookEvent::factory()->create();

    $request = resolve(ActionOrchestrator::class)->dispatch(
        type: 'delete_series',
        sourceService: 'emby',
        targetService: 'sonarr',
        payload: [],
        webhookEvent: $webhookEvent,
    );

    expect($request->webhook_event_id)->toBe($webhookEvent->id);
});
