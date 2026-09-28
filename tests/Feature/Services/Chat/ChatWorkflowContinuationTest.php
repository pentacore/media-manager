<?php

declare(strict_types=1);

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use App\Services\Chat\ChatWorkflowContinuation;
use App\Services\Chat\WorkflowContinuationRefused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $attributes
 */
function chatContinuationWorkflow(User $user, array $attributes = []): AiProposedWorkflow
{
    return AiProposedWorkflow::factory()->for($user)->create([
        'rationale' => 'Clean up',
        'steps' => [['action' => 'delete_series', 'target' => 'Demo Show', 'reason' => 'Unwatched']],
        ...$attributes,
    ]);
}

test('approving returns the execute prompt and marks the workflow approved', function (): void {
    $user = User::factory()->admin()->create();
    $workflow = chatContinuationWorkflow($user);

    $prompt = resolve(ChatWorkflowContinuation::class)->continueFrom($workflow->id, 'approved', $user);

    expect($prompt)->toBe(sprintf(
        "The user has APPROVED workflow %s. Execute each step now using the destructive tool that matches its action — do NOT call ProposeWorkflowTool again for these steps.\n\n1. delete_series on Demo Show — Unwatched",
        $workflow->id,
    ))->and($workflow->fresh()->status)->toBe(AiProposedWorkflowStatus::Approved);
});

test('declining returns the acknowledge prompt and marks the workflow declined', function (): void {
    $user = User::factory()->admin()->create();
    $workflow = chatContinuationWorkflow($user);

    $prompt = resolve(ChatWorkflowContinuation::class)->continueFrom($workflow->id, 'declined', $user);

    expect($prompt)->toBe(sprintf('The user has DECLINED workflow %s. Acknowledge the decline and ask what they would like to do instead.', $workflow->id))
        ->and($workflow->fresh()->status)->toBe(AiProposedWorkflowStatus::Declined);
});

test('another users workflow is refused as not found', function (): void {
    $workflow = chatContinuationWorkflow(User::factory()->admin()->create());

    try {
        resolve(ChatWorkflowContinuation::class)->continueFrom($workflow->id, 'approved', User::factory()->admin()->create());
        $this->fail('Expected the foreign workflow to be refused.');
    } catch (WorkflowContinuationRefused $workflowContinuationRefused) {
        expect($workflowContinuationRefused->status)->toBe(404)
            ->and($workflowContinuationRefused->getMessage())->toBe('Workflow not found.')
            ->and($workflow->fresh()->status)->toBe(AiProposedWorkflowStatus::Proposed);
    }
});

test('a second decision on the same workflow is refused as no longer pending', function (): void {
    $user = User::factory()->admin()->create();
    $workflow = chatContinuationWorkflow($user);
    $chatWorkflowContinuation = resolve(ChatWorkflowContinuation::class);
    $chatWorkflowContinuation->continueFrom($workflow->id, 'approved', $user);

    try {
        $chatWorkflowContinuation->continueFrom($workflow->id, 'approved', $user);
        $this->fail('Expected the second approval to be refused.');
    } catch (WorkflowContinuationRefused $workflowContinuationRefused) {
        expect($workflowContinuationRefused->status)->toBe(422)
            ->and($workflowContinuationRefused->getMessage())->toBe('Workflow is no longer pending.');
    }
});

test('claiming stamps an unstamped proposal and never steals one stamped for another conversation', function (): void {
    $user = User::factory()->admin()->create();
    $workflow = chatContinuationWorkflow($user, ['conversation_id' => null]);
    $conversationId = (string) Str::uuid7();
    $chatWorkflowContinuation = resolve(ChatWorkflowContinuation::class);

    $claimed = $chatWorkflowContinuation->claimProposed($user, CarbonImmutable::now()->subMinute(), $conversationId);
    $refreshed = $workflow->fresh();

    expect($claimed)->toBe(['id' => $workflow->id, 'rationale' => 'Clean up', 'steps' => $refreshed->steps])
        ->and($refreshed->conversation_id)->toBe($conversationId)
        ->and($chatWorkflowContinuation->claimProposed($user, CarbonImmutable::now()->subMinute(), (string) Str::uuid7()))->toBeNull();
});
