<?php

declare(strict_types=1);

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * pendingWorkflow refuses to stamp conversations the caller doesn't own, so
 * every test needs a real conversation row for the acting user.
 */
function seedOwnedConversation(User $user, string $id = '01890000-0000-7000-8000-000000000001'): string
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('returns the freshly proposed workflow for the user and claims it', function (): void {
    $admin = User::factory()->admin()->create();

    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $admin->id,
        'status' => AiProposedWorkflowStatus::Proposed,
        'conversation_id' => null,
    ]);

    $conversationId = seedOwnedConversation($admin);

    $response = $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => $conversationId]))
        ->assertOk();

    expect($response->json('workflow.id'))->toBe($workflow->id)
        ->and($workflow->refresh()->conversation_id)->toBe('01890000-0000-7000-8000-000000000001');
});

test('returns null when nothing proposed', function (): void {
    $admin = User::factory()->admin()->create();
    seedOwnedConversation($admin);

    $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => '01890000-0000-7000-8000-000000000001']))
        ->assertOk()
        ->assertJson(['workflow' => null]);
});

test('does not return another users workflow', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    seedOwnedConversation($admin);

    AiProposedWorkflow::factory()->create([
        'user_id' => $other->id,
        'status' => AiProposedWorkflowStatus::Proposed,
    ]);

    $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => '01890000-0000-7000-8000-000000000001']))
        ->assertOk()
        ->assertJson(['workflow' => null]);
});

test('refuses to stamp a conversation the caller does not own', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $foreign = seedOwnedConversation($other, '01890000-0000-7000-8000-00000000000f');

    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $admin->id,
        'status' => AiProposedWorkflowStatus::Proposed,
        'conversation_id' => null,
    ]);

    $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => $foreign]))
        ->assertNotFound();

    expect($workflow->refresh()->conversation_id)->toBeNull();
});

test('a sibling conversation cannot steal an already-stamped proposal', function (): void {
    $admin = User::factory()->admin()->create();
    $original = seedOwnedConversation($admin, '01890000-0000-7000-8000-00000000000a');
    $sibling = seedOwnedConversation($admin, '01890000-0000-7000-8000-00000000000b');

    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $admin->id,
        'status' => AiProposedWorkflowStatus::Proposed,
        'conversation_id' => $original,
    ]);

    $this->actingAs($admin)
        ->getJson(route('ai.chat.pending-workflow', ['conversation_id' => $sibling]))
        ->assertOk()
        ->assertJson(['workflow' => null]);

    expect($workflow->refresh()->conversation_id)->toBe($original);
});
