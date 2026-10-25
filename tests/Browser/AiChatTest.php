<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Enums\ActionRequestStatus;
use App\Enums\AiProposedWorkflowStatus;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\AiProposedWorkflow;
use App\Models\ChatAttachment;
use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;

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

    $pendingAwaitablePage = visit('/dashboard');

    $pendingAwaitablePage->assertNoSmoke()
        ->click('AI Assistant')
        ->assertVisible('[data-slot="ai-chat-resize"]')
        ->assertScript('document.querySelector(\'[data-slot="sheet-content"]\').offsetWidth', 560);

    // Drag the handle 240px to the left, which widens the right-anchored panel.
    $pendingAwaitablePage->script(<<<'JS'
        (() => {
            const handle = document.querySelector('[data-slot="ai-chat-resize"]');
            const start = handle.getBoundingClientRect().left;
            const base = { bubbles: true, cancelable: true, pointerId: 1, pointerType: 'mouse', button: 0, clientY: 300 };

            handle.dispatchEvent(new PointerEvent('pointerdown', { ...base, clientX: start }));
            window.dispatchEvent(new PointerEvent('pointermove', { ...base, clientX: start - 240 }));
            window.dispatchEvent(new PointerEvent('pointerup', { ...base, clientX: start - 240 }));
        })()
    JS);

    $pendingAwaitablePage->assertScript('document.querySelector(\'[data-slot="sheet-content"]\').offsetWidth', 800)
        ->assertScript("localStorage.getItem('mm.ai-chat.width')", '800')
        ->assertNoJavaScriptErrors();

    // The stored width survives a full page load.
    $pendingAwaitablePage->navigate('/dashboard')
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

test('a workflow continuation stopped by the hard cap explains the budget', function (): void {
    $admin = User::factory()->admin()->create();
    $callCount = 0;

    MediaAgent::fake(function () use ($admin, &$callCount): string {
        $callCount++;

        if ($callCount === 1) {
            AiProposedWorkflow::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $admin->id,
                'conversation_id' => null,
                'rationale' => 'Cleaning up unwatched series',
                'steps' => [['action' => 'delete_series', 'target' => 'Demo', 'reason' => 'Unwatched']],
                'status' => AiProposedWorkflowStatus::Proposed,
            ]);

            return 'Here is a proposed workflow.';
        }

        throw new AiBudgetExceededException(101.0, 100.0);
    });

    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Clean up my old shows please.')
        ->click('Send')
        ->assertSee('Proposed workflow')
        ->click('Approve')
        ->assertSee('Monthly AI hard cap reached')
        ->assertDontSee('budget_exceeded');
});

test('streamed tool calls render as status chips and reasoning collapses', function (): void {
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'GetServiceStatusTool', arguments: []),
        AgentResponse::fakeWithReasoning('Checked the service list first', 'All services are healthy.'),
    ]);
    // The tool only reads service connections from the database; the SSR
    // render is the one outbound request the page itself makes.
    Http::preventStrayRequests();
    Http::allowStrayRequests([config('inertia.ssr.url').'/*']);

    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Are my services up?')
        ->click('Send')
        ->assertSee('All services are healthy.')
        ->assertVisible('[data-tool-chip="done"]')
        ->assertSeeIn('[data-reasoning-block]', 'Thought process');
});

test('a delegated stuck-download investigation renders as a tool chip', function (): void {
    StuckDownloadInvestigatorAgent::fake([[
        'service' => 'sonarr', 'download_id' => 'abc', 'title' => 'Show S01E01',
        'files' => ['/dl/show.mkv | mapped | not an upgrade'], 'recommendation' => 'remove',
        'blocklist' => false, 'search_replacement' => false, 'reason' => 'Existing file is better.',
    ]]);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'InvestigateStuckDownload', arguments: ['task' => 'Why is download abc stuck?']),
        'It is not an upgrade; I can remove it.',
    ]);
    Http::preventStrayRequests();
    Http::allowStrayRequests([config('inertia.ssr.url').'/*']);

    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'why is it stuck?')
        ->click('Send')
        ->assertSee('It is not an upgrade; I can remove it.')
        ->assertVisible('[data-tool-chip="done"]');

    StuckDownloadInvestigatorAgent::assertPromptedTimes(1);
});

test('a failed turn shows a friendly error', function (): void {
    MediaAgent::fake(function (): never {
        throw new ProviderConnectionException('down');
    });

    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Hello?')
        ->click('Send')
        ->assertSee("Couldn't reach the AI provider");
});

test('a failed first turn joins its stored conversation so a retry continues it', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    $calls = 0;
    MediaAgent::fake(function () use (&$calls): ToolCall|string {
        $calls++;

        return match ($calls) {
            1 => new ToolCall(id: 'c1', name: 'GetServiceStatusTool', arguments: []),
            2 => throw new ProviderConnectionException('down'),
            default => 'Recovered on the retry.',
        };
    });
    Http::preventStrayRequests();
    Http::allowStrayRequests([config('inertia.ssr.url').'/*']);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Are my services up?')
        ->click('Send')
        ->assertSee("Couldn't reach the AI provider")
        ->assertVisible('[data-failed-turn]')
        ->type('textarea[placeholder^="Ask"]', 'Try again please')
        ->click('Send')
        ->assertSee('Recovered on the retry.');

    expect(DB::table('agent_conversations')->where('participant_id', $admin->id)->count())->toBe(1);
});

test('a tool that reports an error renders a failed chip', function (): void {
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'RemoveStuckDownloadChatTool', arguments: []),
        'I could not remove that download.',
    ]);
    Http::preventStrayRequests();
    Http::allowStrayRequests([config('inertia.ssr.url').'/*']);

    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'remove the stuck download')
        ->click('Send')
        ->assertSee('I could not remove that download.')
        ->assertVisible('[data-tool-chip="failed"]')
        ->assertMissing('[data-tool-chip="done"]');
});

test('a structured sub-agent finishes as a done tool chip without streamed activity', function (): void {
    StuckDownloadInvestigatorAgent::fake([[
        'service' => 'sonarr', 'download_id' => 'abc', 'title' => 'Show S01E01',
        'files' => ['/dl/show.mkv | mapped | not an upgrade'], 'recommendation' => 'remove',
        'blocklist' => false, 'search_replacement' => false,
        'reason' => 'The existing file already has the better Custom Format score, so this grab is not an upgrade and the release sits in the queue waiting for manual action.',
    ]]);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'InvestigateStuckDownload', arguments: ['task' => 'Why is download abc stuck?']),
        'It is not an upgrade; I can remove it.',
    ]);
    Http::preventStrayRequests();
    Http::allowStrayRequests([config('inertia.ssr.url').'/*']);

    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'why is it stuck?')
        ->click('Send')
        ->assertSee('It is not an upgrade; I can remove it.')
        ->assertVisible('[data-tool-chip="done"]')
        ->assertNotPresent('[data-tool-activity]');
});

test('a stored failed turn renders as a failed reply when reopened', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Broken chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'Is Sonarr up?');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', '', status: 'failed');

    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSee('Is Sonarr up?')
        ->assertSeeIn('[data-chat-thread] [data-failed-turn]', 'This reply failed.');
});

test('a picked attachment shows as a removable chip in the composer', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->attach('[data-attachment-input]', base_path('tests/Fixtures/screenshot.png'))
        ->assertVisible('[data-attachment-chips] [data-attachment-chip]')
        ->assertSeeIn('[data-attachment-chips]', 'screenshot.png')
        ->click('[data-attachment-remove]')
        ->assertMissing('[data-attachment-chip]');
});

test('a stored attachment is shown when the conversation is reopened', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    $chatAttachment = ChatAttachment::factory()->create(['user_id' => $admin->id, 'conversation_id' => $conversationId]);

    aiChatInsertConversation($admin, $conversationId, 'Codec question');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'What is this?', attachments: [
        ['type' => 'stored-image', 'name' => null, 'path' => $chatAttachment->path, 'disk' => 'local'],
    ]);
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', 'That is a codec error.');

    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSee('That is a codec error.')
        ->assertVisible('[data-chat-thread] [data-attachment-chip]')
        ->assertSeeIn('[data-chat-thread] [data-attachment-chips]', 'screenshot.png');
});

test('a reopened reply links to the benchmark case form by its invocation or message id', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Benchmark chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'First question');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', 'Traced answer', meta: ['invocation_id' => 'inv-abc-123']);
    aiChatInsertMessage($admin, $conversationId, 3, 'user', 'Second question');
    aiChatInsertMessage($admin, $conversationId, 4, 'assistant', 'Old answer');

    $this->actingAs($admin);

    $oldMessageId = DB::table('agent_conversation_messages')->where('content', 'Old answer')->value('id');

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSee('Old answer')
        ->assertVisible('[data-chat-thread] [data-chat-save-benchmark-case][href*="from_invocation=inv-abc-123"]')
        ->assertVisible("[data-chat-thread] [data-chat-save-benchmark-case][href*=\"from_chat_message={$oldMessageId}\"]")
        ->assertNoJavaScriptErrors();
});

test('a streamed reply links to the benchmark case form by its invocation id', function (): void {
    MediaAgent::fake(['Streamed answer.']);
    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Say something')
        ->click('Send')
        ->assertSee('Streamed answer.')
        ->assertVisible('[data-chat-thread] [data-chat-save-benchmark-case][href*="from_invocation="]')
        ->assertNoJavaScriptErrors();
});

test('a workflow proposal reply offers no benchmark case link', function (): void {
    $admin = User::factory()->admin()->create();

    MediaAgent::fake(function () use ($admin): string {
        AiProposedWorkflow::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $admin->id,
            'conversation_id' => null,
            'rationale' => 'Cleaning up unwatched series',
            'steps' => [['action' => 'delete_series', 'target' => 'Demo Show', 'reason' => 'Unwatched 8mo']],
            'status' => AiProposedWorkflowStatus::Proposed,
        ]);

        return 'I have proposed a workflow.';
    });
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Clean up my old shows please.')
        ->click('Send')
        ->assertSee('I have proposed a workflow.')
        ->assertSee('Proposed workflow')
        ->assertMissing('[data-chat-save-benchmark-case]')
        ->assertNoJavaScriptErrors();
});

test('long conversations load earlier messages on demand', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Long chat');

    foreach (range(1, 35) as $i) {
        aiChatInsertMessage($admin, $conversationId, $i, $i % 2 === 1 ? 'user' : 'assistant', "message {$i}");
    }

    $this->actingAs($admin);

    // The newest page holds messages 6–35; "message 5" is the only older text
    // that isn't a prefix of a newer one.
    $webpage = visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSee('message 35')
        ->assertDontSee('message 5')
        ->assertVisible('[data-load-earlier]');

    // A DOM click, not ->click(): Playwright would first scroll the button
    // into view, and that upward scroll to the top of the thread starts the
    // scroll-to-top auto-load, which loads the last page and removes the
    // button before the click lands.
    $webpage->script("document.querySelector('[data-load-earlier]').click()");
    $webpage->assertSee('message 5');
});

function aiChatInsertConversation(User $user, string $conversationId, string $title): void
{
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<array<string, mixed>>  $attachments
 * @param  array<string, mixed>  $meta
 * @param  list<array<string, mixed>>  $toolCalls  stored tool calls, each carrying its folded-in `result` as the conversation store writes them
 */
function aiChatInsertMessage(User $user, string $conversationId, int $position, string $role, string $content, array $attachments = [], string $status = 'completed', array $meta = [], array $toolCalls = []): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'agent' => MediaAgent::class,
        'role' => $role,
        'content' => $content,
        'attachments' => json_encode($attachments),
        'steps' => $role === 'user'
            ? '[]'
            : json_encode([['content' => $content, 'tool_calls' => $toolCalls, 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
        'usage' => '{}',
        'meta' => json_encode($meta === [] ? new stdClass : $meta),
        'status' => $status,
        'created_at' => now()->addSeconds($position),
        'updated_at' => now()->addSeconds($position),
    ]);
}

/**
 * A stored OfferChoicesTool call (with its result) offering the given labels.
 *
 * @param  list<string>  $labels
 * @param  array<string, string>  $descriptions  keyed by label
 * @return array<string, mixed>
 */
function aiChatChoicesResult(string $toolCallId, string $question, array $labels, bool $multiple = false, array $descriptions = []): array
{
    return [
        'id' => $toolCallId,
        'name' => 'OfferChoicesTool',
        'arguments' => [],
        'result' => json_encode([
            'choices' => [
                'question' => $question,
                'multiple' => $multiple,
                'options' => array_map(fn (string $label): array => ['label' => $label, 'description' => $descriptions[$label] ?? null], $labels),
            ],
            'instruction' => 'End your turn now.',
        ]),
        'result_id' => null,
    ];
}

/**
 * A stored destructive-tool call (with its result) that queued the given action request.
 *
 * @return array<string, mixed>
 */
function aiChatQueuedActionResult(ActionRequest $actionRequest): array
{
    return [
        'id' => 'call_queue',
        'name' => 'DeleteMediaTool',
        'arguments' => [],
        'result' => json_encode([
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => 'pending',
            'requires_approval' => true,
            'title' => $actionRequest->title,
        ]),
        'result_id' => null,
    ];
}

/**
 * Hold every chat stream request open until it is aborted, recording the
 * abort, and keep the real fetch at window.__realFetch for later turns.
 */
function aiChatHoldStreamScript(): string
{
    return <<<'JS'
        () => {
            window.__chatStreamAborted = false;
            window.__realFetch = window.fetch.bind(window);
            window.fetch = (input, init = {}) => {
                const url = typeof input === 'string' ? input : input.url;

                if (! url.includes('/ai/chat/stream')) {
                    return window.__realFetch(input, init);
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

test('the stop button aborts a streaming reply and frees the composer', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['This reply never arrives.']);
    $this->actingAs(User::factory()->admin()->create());

    $pendingAwaitablePage = visit(route('ai.chat', absolute: false));
    $pendingAwaitablePage->assertNoSmoke();
    $pendingAwaitablePage->script(aiChatHoldStreamScript());

    $pendingAwaitablePage->type('textarea[placeholder^="Ask"]', 'Find me something to watch')
        ->click('Send')
        ->assertVisible('[data-chat-stop]')
        ->click('[data-chat-stop]')
        ->assertSeeIn('[data-chat-thread] [data-stopped-turn]', 'Stopped.')
        ->assertMissing('[data-chat-stop]')
        ->assertMissing('[data-chat-error]')
        ->assertScript('window.__chatStreamAborted', true)
        ->assertNoJavaScriptErrors();

    MediaAgent::assertNeverPrompted();
});

test('a message after stopping a new chat is answered normally', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['Here is a fresh answer.']);
    $this->actingAs(User::factory()->admin()->create());

    $pendingAwaitablePage = visit(route('ai.chat', absolute: false));
    $pendingAwaitablePage->assertNoSmoke();
    $pendingAwaitablePage->script(aiChatHoldStreamScript());

    $pendingAwaitablePage->type('textarea[placeholder^="Ask"]', 'First question')
        ->click('Send')
        ->click('[data-chat-stop]')
        ->assertVisible('[data-stopped-turn]');

    $pendingAwaitablePage->script('() => { window.fetch = window.__realFetch; }');

    $pendingAwaitablePage->type('textarea[placeholder^="Ask"]', 'Second question')
        ->click('Send')
        ->assertSeeIn('[data-chat-thread]', 'Here is a fresh answer.')
        ->assertMissing('[data-chat-error]')
        ->assertNoJavaScriptErrors();
});

test('the active conversation can be renamed from the chat header', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['Here you go.']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Weekend movie ideas')
        ->click('Send')
        ->assertSeeIn('[data-chat-thread]', 'Here you go.')
        ->click('[data-chat-rename]')
        ->type('[data-chat-rename-input]', 'Weekend picks')
        ->click('[data-chat-rename-save]')
        ->assertSeeIn('[data-chat-title]', 'Weekend picks')
        ->assertNoJavaScriptErrors();

    expect(DB::table('agent_conversations')->value('title'))->toBe('Weekend picks');
});

test('switching to advisory mode changes the composer hint', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-chat-mode="advisory"]')
        ->assertAttribute('[data-chat-input]', 'placeholder', 'Ask MediaAgent · advisory mode (read-only)…')
        ->assertNoJavaScriptErrors();
});

test('a streamed OfferChoices call renders a choice card that takes a pick', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    $calls = 0;
    MediaAgent::fake(function () use (&$calls): ToolCall|string {
        $calls++;

        return match ($calls) {
            1 => new ToolCall(id: 'call_pick', name: 'OfferChoicesTool', arguments: [
                'question' => 'Which download should I import?',
                'options' => [['label' => 'Import the first'], ['label' => 'Import the second', 'description' => 'The 1080p release']],
            ]),
            2 => 'Pick one below.',
            default => 'Importing the second one.',
        };
    });
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->type('textarea[placeholder^="Ask"]', 'Two downloads are stuck')
        ->click('Send')
        // The card arrives mid-stream; it takes a pick once the turn ends.
        ->assertSeeIn('[data-chat-thread]', 'Pick one below.')
        ->assertSeeIn('[data-chat-thread] [data-choice-card]', 'Which download should I import?')
        ->assertSeeIn('[data-choice-option="Import the second"] [data-choice-description]', 'The 1080p release')
        ->click('[data-choice-option="Import the second"]')
        ->assertSeeIn('[data-chat-thread]', 'Importing the second one.')
        ->assertDisabled('[data-choice-option="Import the first"]')
        ->assertVisible('[data-choice-option="Import the second"][data-choice-chosen]')
        ->assertNoJavaScriptErrors();

    expect(DB::table('agent_conversation_messages')->where('role', 'user')->where('content', 'Import the second')->exists())->toBeTrue();
});

test('a reloaded conversation ending in a choices card sends the clicked option', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['Going with B.']);
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Choice chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'Which release?');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', '', toolCalls: [
        aiChatChoicesResult('call_1', 'Pick a release', ['A', 'B'], descriptions: ['B' => 'Smaller 720p file']),
    ]);

    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSeeIn('[data-chat-thread] [data-choice-card]', 'Pick a release')
        ->assertSeeIn('[data-choice-card] [data-choice-option="B"] [data-choice-description]', 'Smaller 720p file')
        ->click('[data-choice-option="B"]')
        ->assertSeeIn('[data-chat-thread]', 'Going with B.')
        ->assertVisible('[data-save-as-template][href*="body=B"]')
        ->assertDisabled('[data-choice-option="A"]')
        ->assertDisabled('[data-choice-option="B"]')
        ->assertVisible('[data-choice-option="B"][data-choice-chosen]')
        ->assertNoJavaScriptErrors();

    expect(DB::table('agent_conversation_messages')->where('role', 'user')->where('content', 'B')->exists())->toBeTrue();
});

test('a multi-select choices card confirms the ticked options together', function (): void {
    Bus::fake([GenerateConversationTitle::class]);
    MediaAgent::fake(['Importing A and C.']);
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Multi choice chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'Which files?');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', 'Pick the files to import.', toolCalls: [
        aiChatChoicesResult('call_1', 'Which files should I import?', ['A', 'B', 'C'], multiple: true),
    ]);

    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSeeIn('[data-chat-thread] [data-choice-card]', 'Which files should I import?')
        ->assertDisabled('[data-choice-confirm]')
        ->click('[data-choice-toggle="C"]')
        ->click('[data-choice-toggle="A"]')
        ->assertEnabled('[data-choice-confirm]')
        ->click('[data-choice-confirm]')
        ->assertSeeIn('[data-chat-thread]', 'Importing A and C.')
        ->assertDisabled('[data-choice-confirm]')
        ->assertVisible('[data-choice-toggle="A"][data-choice-chosen]')
        ->assertVisible('[data-choice-toggle="C"][data-choice-chosen]')
        ->assertMissing('[data-choice-toggle="B"][data-choice-chosen]')
        ->assertNoJavaScriptErrors();

    expect(DB::table('agent_conversation_messages')->where('role', 'user')->where('content', 'A, C')->exists())->toBeTrue();
});

test('an answered choices card on an older reply stays locked and shows the pick', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Answered choice chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'Which release?');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', 'Pick one.', toolCalls: [
        aiChatChoicesResult('call_1', 'Pick a release', ['A', 'B']),
    ]);
    aiChatInsertMessage($admin, $conversationId, 3, 'user', 'B');
    aiChatInsertMessage($admin, $conversationId, 4, 'assistant', 'Grabbed release B.');

    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSeeIn('[data-chat-thread]', 'Grabbed release B.')
        ->assertVisible('[data-chat-thread] [data-choice-card]')
        ->assertDisabled('[data-choice-option="A"]')
        ->assertDisabled('[data-choice-option="B"]')
        ->assertVisible('[data-choice-option="B"][data-choice-chosen]')
        ->assertMissing('[data-choice-option="A"][data-choice-chosen]')
        ->assertNoJavaScriptErrors();
});

test('a queued action card approves the pending request inline', function (): void {
    Queue::fake([ExecuteActionRequest::class]);
    $admin = User::factory()->admin()->create();
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Delete series "Severance"']);
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Approval chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'Delete Severance');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', 'I queued the deletion for approval.', toolCalls: [
        aiChatQueuedActionResult($actionRequest),
    ]);

    $this->actingAs($admin);

    $webpage = visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSeeIn('[data-action-approval-card]', 'Delete series "Severance"')
        ->assertSeeIn('[data-action-approval-card] [data-action-status]', 'pending');

    // The pending-actions badge follows the ActionRequest broadcast, so an
    // inline review must not reload the open page.
    $webpage->script(<<<'JS'
        (() => {
            window.__inertiaVisitsAfterReview = [];
            document.addEventListener('inertia:before', (event) => window.__inertiaVisitsAfterReview.push(String(event.detail.visit.url)));
        })()
        JS);

    $webpage->click('[data-action-approve]')
        ->assertSeeIn('[data-action-approval-card] [data-action-status]', 'approved')
        ->assertMissing('[data-action-approve]')
        ->assertMissing('[data-action-reject]')
        ->assertVisible("[data-action-queue-link][href*=\"request={$actionRequest->id}\"]")
        ->assertNoJavaScriptErrors();

    expect($webpage->script('window.__inertiaVisitsAfterReview'))->toBe([])
        ->and($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('a queued action card rejects the pending request inline', function (): void {
    Queue::fake([ExecuteActionRequest::class]);
    $admin = User::factory()->admin()->create();
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Delete series "Severance"']);
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Rejection chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'Delete Severance');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', 'I queued the deletion for approval.', toolCalls: [
        aiChatQueuedActionResult($actionRequest),
    ]);

    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSeeIn('[data-action-approval-card] [data-action-status]', 'pending')
        ->click('[data-action-reject]')
        ->assertSee('1 rejected')
        ->assertSeeIn('[data-action-approval-card] [data-action-status]', 'rejected')
        ->assertMissing('[data-action-approve]')
        ->assertMissing('[data-action-reject]')
        ->assertNoJavaScriptErrors();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Rejected);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('a queued action card reviewed elsewhere first shows it changed and leaves the request alone', function (): void {
    Queue::fake([ExecuteActionRequest::class]);
    $admin = User::factory()->admin()->create();
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Delete series "Severance"']);
    $conversationId = (string) Str::uuid7();

    aiChatInsertConversation($admin, $conversationId, 'Raced approval chat');
    aiChatInsertMessage($admin, $conversationId, 1, 'user', 'Delete Severance');
    aiChatInsertMessage($admin, $conversationId, 2, 'assistant', 'I queued the deletion for approval.', toolCalls: [
        aiChatQueuedActionResult($actionRequest),
    ]);

    $this->actingAs($admin);

    $webpage = visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSeeIn('[data-action-approval-card] [data-action-status]', 'pending');

    // Approved from the Action Queue after the chat loaded.
    $actionRequest->update(['status' => ActionRequestStatus::Approved]);

    $webpage->click('[data-action-approve]')
        ->assertSee('1 skipped')
        ->assertSeeIn('[data-action-approval-card] [data-action-status]', 'Changed elsewhere')
        ->assertMissing('[data-action-approve]')
        ->assertMissing('[data-action-reject]')
        ->assertVisible('[data-action-queue-link]')
        ->assertNoJavaScriptErrors();

    expect($actionRequest->fresh())
        ->status->toBe(ActionRequestStatus::Approved)
        ->approved_by->toBeNull();
    Queue::assertNotPushed(ExecuteActionRequest::class);
});
