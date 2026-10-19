<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiProposedWorkflowStatus;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\ProviderConnectionException;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Bus::fake([GenerateConversationTitle::class]);
});

/**
 * Holds every chat stream request open until it is aborted, and records the
 * abort on window.__chatStreamAborted.
 */
function chatTurnsHoldStreamScript(): string
{
    return <<<'JS'
        () => {
            window.__chatStreamAborted = false;
            const realFetch = window.fetch.bind(window);
            window.fetch = (input, init = {}) => {
                const url = typeof input === 'string' ? input : input.url;

                if (! url.includes('/ai/chat/stream')) {
                    return realFetch(input, init);
                }

                return new Promise((resolve, reject) => {
                    init.signal?.addEventListener('abort', () => {
                        window.__chatStreamAborted = true;
                        reject(new DOMException('The user aborted a request.', 'AbortError'));
                    });
                });
            };
        }
        JS;
}

test('a first turn names the conversation after its first message', function (): void {
    MediaAgent::fake(["Here are this week's example episodes."]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-chat-title]', 'New chat')
        ->type('textarea[placeholder^="Ask"]', 'Which example shows air this week?')
        ->click('Send')
        ->assertSeeIn('[data-chat-thread]', "Here are this week's example episodes.")
        ->assertSeeIn('[data-chat-title]', 'Which example shows air this week?')
        ->assertNoSmoke();
});

test('a declined workflow whose continuation fails offers approve and decline again', function (): void {
    $admin = User::factory()->admin()->create();
    $turns = new class
    {
        public int $count = 0;
    };

    MediaAgent::fake(function () use ($admin, $turns): string {
        $turns->count++;

        if ($turns->count === 1) {
            AiProposedWorkflow::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $admin->id,
                'conversation_id' => null,
                'rationale' => 'Clean up an example unwatched series',
                'steps' => [['action' => 'delete_series', 'target' => 'Example Show', 'reason' => 'Unwatched']],
                'status' => AiProposedWorkflowStatus::Proposed,
            ]);

            return 'Here is a proposed example workflow.';
        }

        throw new ProviderConnectionException('down');
    });

    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Clean up my example shows.')
        ->click('Send')
        ->assertSeeIn('[data-workflow-proposal]', 'Decline')
        ->click('Decline')
        ->assertSee("Couldn't reach the AI provider")
        ->assertSeeIn('[data-workflow-proposal]', 'Approve')
        ->assertSeeIn('[data-workflow-proposal]', 'Decline');
});

test('closing the assistant sheet mid-reply aborts the stream', function (): void {
    MediaAgent::fake(['Never shown.']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('AI Assistant')
        ->assertVisible('[data-chat-thread]');
    $webpage->script(chatTurnsHoldStreamScript());
    $webpage->type('textarea[placeholder^="Ask"]', 'Which example shows air this week?')
        ->click('Send')
        ->assertSeeIn('[data-chat-thread]', 'thinking…');

    $webpage->keys('[data-slot="sheet-content"]', 'Escape');
    $webpage->script(confirmDialogWaitUntilScript('window.__chatStreamAborted === true'));

    expect($webpage->script('window.__chatStreamAborted'))->toBeTrue();
    $webpage->assertMissing('[data-chat-thread]')
        ->assertNoSmoke();
});

test('reopening the assistant sheet shows the conversation it left', function (): void {
    MediaAgent::fake(['The example answer.']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('AI Assistant')
        ->type('textarea[placeholder^="Ask"]', 'Ask the example question.')
        ->click('Send')
        ->assertSeeIn('[data-chat-thread]', 'The example answer.');

    $webpage->keys('[data-slot="sheet-content"]', 'Escape');
    $webpage->script(confirmDialogWaitUntilScript("!document.querySelector('[data-chat-thread]')"));
    $webpage->click('AI Assistant');
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-chat-thread]')?.innerText.includes('The example answer.') === true"));

    $webpage->assertSeeIn('[data-chat-thread]', 'Ask the example question.')
        ->assertSeeIn('[data-chat-thread]', 'The example answer.')
        ->assertNoSmoke();
});
