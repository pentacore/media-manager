<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('admin can open AI chat and send a message', function (): void {
    MediaAgent::fake(['Sure, here is what I found.']);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoJavaScriptErrors()
        ->assertSee('AI Assistant')
        ->type('input[placeholder^="Ask"]', 'What is on my watchlist?')
        ->click('button[type="submit"]')
        ->assertSee('What is on my watchlist?')
        ->assertSee('Sure, here is what I found.');
});

test('proposed workflow renders confirm card and approval round-trips', function (): void {
    $admin = User::factory()->admin()->create();
    $callCount = 0;
    $workflowId = null;

    MediaAgent::fake(function (string $prompt) use ($admin, &$callCount, &$workflowId): string {
        $callCount++;

        if ($callCount === 1) {
            $workflow = AiProposedWorkflow::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $admin->id,
                'conversation_id' => null,
                'rationale' => 'Cleaning up unwatched series',
                'steps' => [
                    ['action' => 'delete_series', 'target' => 'Demo Show', 'reason' => 'Unwatched 8mo'],
                    ['action' => 'delete_series', 'target' => 'Other Show', 'reason' => 'Unwatched 12mo'],
                    ['action' => 'cleanup_seerr_request', 'target' => 'request 42', 'reason' => 'Stale'],
                ],
                'status' => AiProposedWorkflowStatus::Proposed,
            ]);
            $workflowId = $workflow->id;

            return 'I have proposed a 3-step workflow.';
        }

        expect($prompt)->toContain('APPROVED');
        expect($prompt)->toContain($workflowId);

        return 'All steps executed.';
    });

    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoJavaScriptErrors()
        ->type('input[placeholder^="Ask"]', 'Clean up my old shows please.')
        ->click('button[type="submit"]')
        ->assertSee('I have proposed a 3-step workflow.')
        ->assertSee('Proposed workflow')
        ->assertSee('Cleaning up unwatched series')
        ->assertSee('delete_series')
        ->click('Approve')
        ->assertSee('Approved.')
        ->assertSee('All steps executed.');

    expect($callCount)->toBeGreaterThanOrEqual(2);
    expect(AiProposedWorkflow::find($workflowId)->status)->toBe(AiProposedWorkflowStatus::Approved);
});

test('proposed workflow can be declined from confirm card', function (): void {
    $admin = User::factory()->admin()->create();
    $workflowId = null;
    $callCount = 0;

    MediaAgent::fake(function (string $prompt) use ($admin, &$workflowId, &$callCount): string {
        $callCount++;

        if ($callCount === 1) {
            $workflow = AiProposedWorkflow::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $admin->id,
                'conversation_id' => null,
                'rationale' => 'Maybe risky',
                'steps' => [['action' => 'delete_series', 'target' => 'Demo', 'reason' => 'Test']],
                'status' => AiProposedWorkflowStatus::Proposed,
            ]);
            $workflowId = $workflow->id;

            return 'Here is a proposed workflow.';
        }

        expect($prompt)->toContain('DECLINED');

        return 'Understood, what next?';
    });

    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoJavaScriptErrors()
        ->type('input[placeholder^="Ask"]', 'Try something risky.')
        ->click('button[type="submit"]')
        ->assertSee('Proposed workflow')
        ->click('Decline')
        ->assertSee('Declined.')
        ->assertSee('Understood, what next?');

    expect(AiProposedWorkflow::find($workflowId)->status)->toBe(AiProposedWorkflowStatus::Declined);
});
