<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->admin = User::factory()->admin()->create();
});

test('approving a workflow transitions it and synthesizes a continuation prompt', function (): void {
    $conversationId = '018f7cf5-3b26-72c8-93e5-6dc5b44f2472';

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $this->admin->id,
        'title' => 'fake',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $this->admin->id,
        'conversation_id' => $conversationId,
        'status' => AiProposedWorkflowStatus::Proposed,
        'steps' => [
            ['action' => 'delete_movie', 'target' => 'Movie A', 'reason' => 'Old'],
        ],
    ]);

    $captured = [];
    MediaAgent::fake(function (string $prompt) use (&$captured): string {
        $captured[] = $prompt;

        return 'Executing now.';
    });

    $response = $this->actingAs($this->admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'I approve the proposed workflow.',
            'conversation_id' => $conversationId,
            'workflow_id' => $workflow->id,
            'workflow_action' => 'approved',
        ]);

    $response->assertOk();

    expect($workflow->fresh()->status)->toBe(AiProposedWorkflowStatus::Approved);
    expect($captured)->toHaveCount(1);
    expect($captured[0])->toContain('APPROVED');
    expect($captured[0])->toContain($workflow->id);
    expect($captured[0])->toContain('delete_movie');
    expect($captured[0])->toContain('Movie A');
});

test('declining a workflow transitions it and synthesizes a decline prompt', function (): void {
    $conversationId = '018f7cf5-3b26-72c8-93e5-6dc5b44f2473';

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $this->admin->id,
        'title' => 'fake',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $this->admin->id,
        'conversation_id' => $conversationId,
        'status' => AiProposedWorkflowStatus::Proposed,
    ]);

    $captured = [];
    MediaAgent::fake(function (string $prompt) use (&$captured): string {
        $captured[] = $prompt;

        return 'Acknowledged decline.';
    });

    $response = $this->actingAs($this->admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'I decline the proposed workflow.',
            'conversation_id' => $conversationId,
            'workflow_id' => $workflow->id,
            'workflow_action' => 'declined',
        ]);

    $response->assertOk();

    expect($workflow->fresh()->status)->toBe(AiProposedWorkflowStatus::Declined);
    expect($captured)->toHaveCount(1);
    expect($captured[0])->toContain('DECLINED');
    expect($captured[0])->toContain($workflow->id);
});

test('workflow continuation rejects when workflow does not belong to the user', function (): void {
    MediaAgent::fake(['noop']);

    $otherUser = User::factory()->admin()->create();
    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $otherUser->id,
        'conversation_id' => null,
        'status' => AiProposedWorkflowStatus::Proposed,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'I approve.',
            'workflow_id' => $workflow->id,
            'workflow_action' => 'approved',
        ]);

    $response->assertNotFound();
    expect($workflow->fresh()->status)->toBe(AiProposedWorkflowStatus::Proposed);
    MediaAgent::assertNeverPrompted();
});

test('workflow continuation rejects when workflow is already terminal', function (): void {
    MediaAgent::fake(['noop']);

    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $this->admin->id,
        'conversation_id' => null,
        'status' => AiProposedWorkflowStatus::Approved,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'I approve.',
            'workflow_id' => $workflow->id,
            'workflow_action' => 'approved',
        ]);

    $response->assertStatus(422);
    expect($workflow->fresh()->status)->toBe(AiProposedWorkflowStatus::Approved);
    MediaAgent::assertNeverPrompted();
});

test('workflow continuation rejects unknown workflow id', function (): void {
    MediaAgent::fake(['noop']);

    $response = $this->actingAs($this->admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'I approve.',
            'workflow_id' => '018f7cf5-0000-0000-0000-000000000000',
            'workflow_action' => 'approved',
        ]);

    $response->assertNotFound();
    MediaAgent::assertNeverPrompted();
});
