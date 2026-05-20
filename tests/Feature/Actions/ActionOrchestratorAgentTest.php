<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionTypeConfig;
use App\Services\Actions\ActionOrchestrator;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestCreated::class]);
    Queue::fake();
});

test('dispatchFromAgent creates a Pending request for requires_approval types', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);

    $request = resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'delete_series',
        sourceService: 'sonarr',
        targetService: 'sonarr',
        payload: ['series_id' => 42],
        rationale: 'Stuck import, no longer monitored.',
    );

    expect($request)->not->toBeNull();
    expect($request->status)->toBe(ActionRequestStatus::Pending);
    expect($request->origin)->toBe('agent');
    expect($request->payload['series_id'])->toBe(42);
    expect($request->payload['agent_rationale'])->toBe('Stuck import, no longer monitored.');

    Event::assertDispatched(ActionRequestCreated::class);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('dispatchFromAgent auto-executes when requires_approval=false', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);

    $request = resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'emby_library_scan',
        sourceService: 'sonarr',
        targetService: 'emby',
        payload: [],
        rationale: 'Import resolved; rescan library.',
    );

    expect($request->status)->toBe(ActionRequestStatus::Approved);
    expect($request->origin)->toBe('agent');
    Queue::assertPushed(ExecuteActionRequest::class);
});

test('dispatchFromAgent ignores the chat advisory mode override', function (): void {
    // Chat AiMode would force dispatch() to Pending — the agent path must not.
    resolve(AiSettings::class)->withMode(AiMode::Advisory);
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);

    $request = resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'emby_library_scan',
        sourceService: 'sonarr',
        targetService: 'emby',
        payload: [],
        rationale: 'Rescan.',
    );

    expect($request->status)->toBe(ActionRequestStatus::Approved);
    Queue::assertPushed(ExecuteActionRequest::class);
});

test('dispatchFromAgent forceRequiresApproval can only tighten the gate', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'resolve_manual_import', 'requires_approval' => false, 'is_enabled' => true]);

    // Forcing approval on an auto-execute rule pushes it to Pending.
    $forced = resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'resolve_manual_import',
        sourceService: 'sonarr',
        targetService: 'sonarr',
        payload: [],
        rationale: 'ambiguous',
        forceRequiresApproval: true,
    );
    expect($forced->status)->toBe(ActionRequestStatus::Pending);
    Queue::assertNotPushed(ExecuteActionRequest::class);

    // forceRequiresApproval=false must NOT relax an approval-required rule.
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    $notRelaxed = resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'delete_series',
        sourceService: 'sonarr',
        targetService: 'sonarr',
        payload: [],
        rationale: 'x',
        forceRequiresApproval: false,
    );
    expect($notRelaxed->status)->toBe(ActionRequestStatus::Pending);
});

test('dispatchFromAgent returns null when config missing or disabled', function (): void {
    expect(resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'never_registered',
        sourceService: 'sonarr',
        targetService: 'sonarr',
        payload: [],
        rationale: 'x',
    ))->toBeNull();

    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => false]);

    expect(resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'delete_series',
        sourceService: 'sonarr',
        targetService: 'sonarr',
        payload: [],
        rationale: 'x',
    ))->toBeNull();

    Event::assertNotDispatched(ActionRequestCreated::class);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});
