<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Enums\AiProposedWorkflowStatus;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiProposedWorkflow;
use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

test('a running sub-agent shows its output under the tool chip', function (): void {
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
        ->assertSeeIn('[data-tool-chip="done"] [data-tool-activity]', 'waiting for manual action');
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
    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        ->assertSee('message 35')
        ->assertDontSee('message 5')
        ->click('[data-load-earlier]')
        ->assertSee('message 5');
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
 */
function aiChatInsertMessage(User $user, string $conversationId, int $position, string $role, string $content, array $attachments = [], string $status = 'completed'): void
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
            : json_encode([['content' => $content, 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
        'usage' => '{}',
        'meta' => '{}',
        'status' => $status,
        'created_at' => now()->addSeconds($position),
        'updated_at' => now()->addSeconds($position),
    ]);
}
