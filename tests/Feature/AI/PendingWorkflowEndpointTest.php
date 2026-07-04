<?php

declare(strict_types=1);

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;

test('returns the freshly proposed workflow for the user and claims it', function (): void {
    $admin = User::factory()->admin()->create();

    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $admin->id,
        'status' => AiProposedWorkflowStatus::Proposed,
        'conversation_id' => null,
    ]);

    $response = $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => '01890000-0000-7000-8000-000000000001']))
        ->assertOk();

    expect($response->json('workflow.id'))->toBe($workflow->id)
        ->and($workflow->refresh()->conversation_id)->toBe('01890000-0000-7000-8000-000000000001');
});

test('returns null when nothing proposed', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => '01890000-0000-7000-8000-000000000001']))
        ->assertOk()
        ->assertJson(['workflow' => null]);
});

test('does not return another users workflow', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    AiProposedWorkflow::factory()->create([
        'user_id' => $other->id,
        'status' => AiProposedWorkflowStatus::Proposed,
    ]);

    $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => '01890000-0000-7000-8000-000000000001']))
        ->assertOk()
        ->assertJson(['workflow' => null]);
});
