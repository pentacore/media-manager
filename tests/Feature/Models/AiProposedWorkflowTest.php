<?php

declare(strict_types=1);

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;

test('factory creates a proposed workflow with valid shape', function (): void {
    $workflow = AiProposedWorkflow::factory()->create();

    expect($workflow->id)->toBeString();
    expect($workflow->status)->toBe(AiProposedWorkflowStatus::Proposed);
    expect($workflow->steps)->toBeArray();
    expect($workflow->steps[0])->toHaveKeys(['action', 'target', 'reason']);
});

test('approved state sets the right status', function (): void {
    $workflow = AiProposedWorkflow::factory()->approved()->create();

    expect($workflow->status)->toBe(AiProposedWorkflowStatus::Approved);
});

test('user relation resolves', function (): void {
    $workflow = AiProposedWorkflow::factory()->create();

    expect($workflow->user)->not->toBeNull();
    expect($workflow->user->id)->toBe($workflow->user_id);
});

test('cascade-deletes when the user is deleted', function (): void {
    $workflow = AiProposedWorkflow::factory()->create();
    $userId = $workflow->user_id;
    $workflowId = $workflow->id;

    User::find($userId)->delete();

    expect(AiProposedWorkflow::find($workflowId))->toBeNull();
});

test('steps cast to array round-trips', function (): void {
    $workflow = AiProposedWorkflow::factory()->create([
        'steps' => [
            ['action' => 'delete_series', 'target' => 'X', 'reason' => 'unwatched'],
        ],
    ]);

    $reloaded = AiProposedWorkflow::find($workflow->id);

    expect($reloaded->steps)->toBeArray();
    expect($reloaded->steps[0]['action'])->toBe('delete_series');
});
