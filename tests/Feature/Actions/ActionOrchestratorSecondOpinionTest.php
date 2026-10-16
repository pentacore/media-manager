<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionVerifierAgent;
use App\Enums\ActionRequestStatus;
use App\Enums\SecondOpinionMode;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Services\Actions\ActionDescription;
use App\Services\Actions\ActionOrchestrator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestCreated::class]);
    Queue::fake();
});

function secondOpinionDispatch(string $type = 'monitor_series'): ?ActionRequest
{
    return resolve(ActionOrchestrator::class)->dispatchFromAgent(
        type: $type,
        sourceService: 'sonarr',
        targetService: 'sonarr',
        payload: ['series_id' => 7],
        rationale: 'The series was added unmonitored.',
        description: new ActionDescription('Monitor Severance', 'Turns monitoring on.'),
    );
}

function secondOpinionRule(string $type, SecondOpinionMode $secondOpinionMode, bool $requiresApproval = false): void
{
    ActionTypeConfig::factory()->create(['type' => $type, 'requires_approval' => $requiresApproval, 'is_enabled' => true, 'second_opinion' => $secondOpinionMode]);
}

test('an off rule never asks the verifier', function (): void {
    secondOpinionRule('monitor_series', SecondOpinionMode::Off);
    DecisionVerifierAgent::fake()->preventStrayPrompts();

    $actionRequest = secondOpinionDispatch();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Approved)->and($actionRequest->second_opinion)->toBeNull();
    DecisionVerifierAgent::assertNeverPrompted();
    Queue::assertPushed(ExecuteActionRequest::class);
});

test('an agreeing verifier leaves the auto-run alone and stores its verdict', function (): void {
    secondOpinionRule('monitor_series', SecondOpinionMode::Reject);
    DecisionVerifierAgent::fake([['agrees' => true, 'reason' => 'Justified.']]);

    $actionRequest = secondOpinionDispatch();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Approved)
        ->and($actionRequest->second_opinion)->toMatchArray(['engine' => 'llm', 'mode' => 'reject', 'agrees' => true, 'failed' => false, 'reason' => 'Justified.']);
    Queue::assertPushed(ExecuteActionRequest::class);
});

test('a disagreement under hold queues the action for approval', function (): void {
    secondOpinionRule('monitor_series', SecondOpinionMode::Hold);
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'Wrong series.']]);

    $actionRequest = secondOpinionDispatch();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending)->and($actionRequest->requires_approval)->toBeTrue();
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('a disagreement under reject creates a rejected request that never runs', function (): void {
    secondOpinionRule('monitor_series', SecondOpinionMode::Reject);
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'Wrong series.']]);

    $actionRequest = secondOpinionDispatch();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Rejected)
        ->and($actionRequest->result)->toBe(['success' => false, 'reason' => 'second_opinion_rejected', 'rejection_reason' => 'Rejected by second opinion: Wrong series.']);
    Queue::assertNotPushed(ExecuteActionRequest::class);
    Event::assertDispatched(ActionRequestCreated::class);
});

test('a failed check under reject holds instead of rejecting', function (): void {
    secondOpinionRule('monitor_series', SecondOpinionMode::Reject);
    DecisionVerifierAgent::fake(fn () => throw new RuntimeException('timeout'));

    $actionRequest = secondOpinionDispatch();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending)
        ->and($actionRequest->second_opinion['failed'])->toBeTrue();
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('a forced-approval type stays pending under hold and is rejected under reject', function (SecondOpinionMode $secondOpinionMode, ActionRequestStatus $actionRequestStatus): void {
    secondOpinionRule('delete_series', $secondOpinionMode, requiresApproval: true);
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'Destructive without cause.']]);

    expect(secondOpinionDispatch('delete_series')->status)->toBe($actionRequestStatus);
    Queue::assertNotPushed(ExecuteActionRequest::class);
})->with([
    'hold' => [SecondOpinionMode::Hold, ActionRequestStatus::Pending],
    'reject' => [SecondOpinionMode::Reject, ActionRequestStatus::Rejected],
]);

test('system and chat dispatches are never verified', function (): void {
    secondOpinionRule('emby_library_scan', SecondOpinionMode::Reject);
    DecisionVerifierAgent::fake()->preventStrayPrompts();

    $actionRequest = resolve(ActionOrchestrator::class)->dispatch(
        type: 'emby_library_scan',
        sourceService: 'sonarr',
        targetService: 'emby',
        payload: [],
        description: new ActionDescription('Scan the library', 'Refreshes Emby.'),
    );

    expect($actionRequest->second_opinion)->toBeNull();
    DecisionVerifierAgent::assertNeverPrompted();
});
