<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionTypeConfig;
use App\Services\Actions\ActionDescription;
use App\Services\Actions\ActionOrchestrator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestCreated::class]);
    Queue::fake();
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);
});

function orchestratorDescription(bool $verified = true): ActionDescription
{
    $description = new ActionDescription('Scan the Emby library', 'Emby will rescan its libraries.', [
        ['label' => 'Emby server', 'value' => 'Emby'],
    ]);

    return $verified ? $description : $description->unverified();
}

test('dispatch persists the description columns', function (): void {
    $actionRequest = resolve(ActionOrchestrator::class)->dispatch(
        type: 'emby_library_scan',
        sourceService: 'radarr',
        targetService: 'emby',
        payload: [],
        description: orchestratorDescription(),
    );

    expect($actionRequest->fresh())
        ->title->toBe('Scan the Emby library')
        ->description->toBe('Emby will rescan its libraries.')
        ->details->toBe([['label' => 'Emby server', 'value' => 'Emby']])
        ->description_verified->toBeTrue()
        ->status->toBe(ActionRequestStatus::Approved);

    Queue::assertPushed(ExecuteActionRequest::class);
});

test('dispatch forces Pending for an unverified description even when the rule auto-executes', function (): void {
    $actionRequest = resolve(ActionOrchestrator::class)->dispatch(
        type: 'emby_library_scan',
        sourceService: 'ai',
        targetService: 'emby',
        payload: [],
        description: orchestratorDescription(verified: false),
    );

    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending)
        ->and($actionRequest->requires_approval)->toBeTrue()
        ->and($actionRequest->description_verified)->toBeFalse();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('dispatch records the given origin', function (): void {
    $actionRequest = resolve(ActionOrchestrator::class)->dispatch(
        type: 'emby_library_scan',
        sourceService: 'ai',
        targetService: 'emby',
        payload: [],
        description: orchestratorDescription(),
        origin: 'chat',
    );

    expect($actionRequest->fresh()->origin)->toBe('chat');
});

test('dispatchFromAgent persists the description and forces Pending when unverified', function (): void {
    $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: 'emby_library_scan',
        sourceService: 'radarr',
        targetService: 'emby',
        payload: [],
        rationale: 'A movie was imported.',
        description: orchestratorDescription(verified: false),
    );

    expect($actionRequest->fresh())
        ->title->toBe('Scan the Emby library')
        ->description_verified->toBeFalse()
        ->status->toBe(ActionRequestStatus::Pending)
        ->origin->toBe('agent');

    Queue::assertNotPushed(ExecuteActionRequest::class);
});
