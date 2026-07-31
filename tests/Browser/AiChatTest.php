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
        ->assertNoSmoke()
        ->assertSee('AI Assistant')
        ->type('textarea[placeholder^="Ask"]', 'What is on my watchlist?')
        ->click('Send')
        ->assertSee('What is on my watchlist?')
        ->assertSee('Sure, here is what I found.');
});

test('assistant panel opens at the wide default and can be resized', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $page = visit('/dashboard');

    $page->assertNoSmoke()
        ->click('AI Assistant')
        ->assertVisible('[data-slot="ai-chat-resize"]')
        ->assertScript('document.querySelector(\'[data-slot="sheet-content"]\').offsetWidth', 560);

    // Drag the handle 240px to the left, which widens the right-anchored panel.
    $page->script(<<<'JS'
        (() => {
            const handle = document.querySelector('[data-slot="ai-chat-resize"]');
            const start = handle.getBoundingClientRect().left;
            const base = { bubbles: true, cancelable: true, pointerId: 1, pointerType: 'mouse', button: 0, clientY: 300 };

            handle.dispatchEvent(new PointerEvent('pointerdown', { ...base, clientX: start }));
            window.dispatchEvent(new PointerEvent('pointermove', { ...base, clientX: start - 240 }));
            window.dispatchEvent(new PointerEvent('pointerup', { ...base, clientX: start - 240 }));
        })()
    JS);

    $page->assertScript('document.querySelector(\'[data-slot="sheet-content"]\').offsetWidth', 800)
        ->assertScript("localStorage.getItem('mm.ai-chat.width')", '800')
        ->assertNoJavaScriptErrors();

    // The stored width survives a full page load.
    $page->navigate('/dashboard')
        ->click('AI Assistant')
        ->assertScript('document.querySelector(\'[data-slot="sheet-content"]\').offsetWidth', 800);
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
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Clean up my old shows please.')
        ->click('Send')
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
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Try something risky.')
        ->click('Send')
        ->assertSee('Proposed workflow')
        ->click('Decline')
        ->assertSee('Declined.')
        ->assertSee('Understood, what next?');

    expect(AiProposedWorkflow::find($workflowId)->status)->toBe(AiProposedWorkflowStatus::Declined);
});
