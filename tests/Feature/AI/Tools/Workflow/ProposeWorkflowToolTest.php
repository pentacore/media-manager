<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Workflow\ProposeWorkflowTool;
use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Laravel\Ai\Tools\Request;

test('stores a proposed workflow row and returns awaiting_confirmation envelope', function (): void {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $result = json_decode((string) (new ProposeWorkflowTool)->handle(new Request([
        'rationale' => 'User asked to delete unwatched horror movies older than 6 months.',
        'steps' => [
            ['action' => 'delete_movie', 'target' => 'Movie A (id 1)', 'reason' => 'Unwatched 8mo'],
            ['action' => 'delete_movie', 'target' => 'Movie B (id 2)', 'reason' => 'Unwatched 9mo'],
        ],
    ])), true);

    expect($result['status'])->toBe('awaiting_confirmation');
    expect($result['workflow_id'])->toBeString();
    expect($result['steps'])->toHaveCount(2);
    expect($result['rationale'])->toContain('User asked');
    expect($result['message'])->toContain('confirm or decline');

    $workflow = AiProposedWorkflow::find($result['workflow_id']);
    expect($workflow)->not->toBeNull();
    expect($workflow->status)->toBe(AiProposedWorkflowStatus::Proposed);
    expect($workflow->user_id)->toBe($user->id);
    expect($workflow->steps)->toHaveCount(2);
});

test('truncates rationale to 1000 chars', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $longRationale = str_repeat('x', 1500);

    $result = json_decode((string) (new ProposeWorkflowTool)->handle(new Request([
        'rationale' => $longRationale,
        'steps' => [['action' => 'delete_movie', 'target' => 'X', 'reason' => 'y']],
    ])), true);

    $workflow = AiProposedWorkflow::find($result['workflow_id']);
    expect(strlen($workflow->rationale))->toBeLessThanOrEqual(1000);
});

test('risk is Read', function (): void {
    expect((new ProposeWorkflowTool)->risk())->toBe(Risk::Read);
});
