<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Routing\ToolGroup;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    resolve(AiSettings::class)->setChatRoutingEnabled(true);
});

/**
 * One answer per ToolGroup key: the classifier fake fills a missing key with
 * a random answer.
 *
 * @param  array<string, float>  $probabilities
 * @return array<string, BooleanAnswer>
 */
function chatToolGroupsAnswers(array $probabilities): array
{
    return collect(ToolGroup::cases())
        ->mapWithKeys(fn (ToolGroup $toolGroup): array => [$toolGroup->value => new BooleanAnswer($probabilities[$toolGroup->value] ?? 0.05)])
        ->all();
}

/**
 * Holds the chat stream open so a test can look at the panel mid-turn.
 */
function chatToolGroupsHoldStreamScript(): string
{
    return <<<'JS'
        () => {
            const realFetch = window.fetch.bind(window);
            window.fetch = (input, init = {}) => {
                const url = typeof input === 'string' ? input : input.url;

                if (! url.includes('/ai/chat/stream')) {
                    return realFetch(input, init);
                }

                return new Promise((resolve, reject) => {
                    init.signal?.addEventListener('abort', () => reject(new DOMException('The user aborted a request.', 'AbortError')));
                });
            };
        }
        JS;
}

/**
 * A conversation owned by $user, optionally pinned, with one reply.
 *
 * @param  list<string>  $pinned
 */
function chatToolGroupsConversation(User $user, array $pinned, bool $withReply = false): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Pinned chat',
        'pinned_tool_groups' => $pinned === [] ? null : json_encode($pinned),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($withReply) {
        foreach ([['user', 'Hello', '{}'], ['assistant', 'Stored reply', json_encode(['tool_groups' => [['group' => 'playback', 'label' => 'Playback', 'source' => 'picked']]])]] as $position => [$role, $content, $meta]) {
            $createdAt = now()->addSeconds($position);
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid7($createdAt),
                'conversation_id' => $id,
                'participant_type' => $user->getMorphClass(),
                'participant_id' => $user->id,
                'agent' => MediaAgent::class,
                'role' => $role,
                'content' => $content,
                'attachments' => '[]',
                'steps' => '[]',
                'usage' => '{}',
                'meta' => $meta,
                'status' => 'completed',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    return $id;
}

test('the strip shows core and pinned groups and pins survive a reload', function (): void {
    ServiceConnection::factory()->prowlarr()->create();
    Classification::fake([chatToolGroupsAnswers([]), chatToolGroupsAnswers([])]);
    MediaAgent::fake(['Pinned reply']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertPresent('[data-chat-tool-strip] [data-tool-core]')
        ->click('[data-tool-add]')
        ->click('[data-tool-pin="indexers"]')
        ->assertPresent('[data-tool-chip="indexers"][data-tool-source="pinned"]')
        ->fill('[data-chat-input]', 'Hi')
        ->keys('[data-chat-input]', 'Enter')
        ->assertSee('Pinned reply');

    $conversationId = (string) DB::table('agent_conversations')->value('id');
    expect(json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('pinned_tool_groups'), true))->toBe(['indexers']);

    $reloaded = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $reloaded->script(appHydratedScript());
    $reloaded->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertPresent('[data-tool-chip="indexers"][data-tool-source="pinned"]');
});

test('unpinning removes the chip', function (): void {
    ServiceConnection::factory()->prowlarr()->create();
    $admin = User::factory()->admin()->create();
    $conversationId = chatToolGroupsConversation($admin, ['indexers']);
    $this->actingAs($admin);

    $webpage = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertPresent('[data-tool-chip="indexers"]')
        ->click('[data-tool-unpin="indexers"]')
        ->assertMissing('[data-tool-chip="indexers"]')
        ->assertEnabled('[data-tool-add]');

    expect(json_decode((string) (DB::table('agent_conversations')->where('id', $conversationId)->value('pinned_tool_groups') ?? '[]'), true))->toBe([]);

    $reloaded = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $reloaded->script(appHydratedScript());
    $reloaded->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertPresent('[data-tool-add]')
        ->assertMissing('[data-tool-chip="indexers"]');
});

test('each reply shows the tools it had and the toggle hides them', function (): void {
    Classification::fake([chatToolGroupsAnswers(['playback' => 0.9])]);
    MediaAgent::fake(['Nothing is playing.']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->fill('[data-chat-input]', 'What is playing?')
        ->keys('[data-chat-input]', 'Enter')
        ->assertSee('Nothing is playing.')
        ->assertPresent('[data-turn-tool="core"]')
        ->assertPresent('[data-turn-tool="playback"]')
        ->click('[data-tool-lines-toggle]')
        ->assertMissing('[data-turn-tool="playback"]')
        ->assertAttribute('[data-tool-lines-toggle]', 'aria-pressed', 'false');

    $conversationId = (string) DB::table('agent_conversations')->value('id');

    $reloaded = $webpage->refresh()->assertNoSmoke();
    $reloaded->script(appHydratedScript());
    $reloaded->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertSeeIn('[data-chat-thread]', 'Nothing is playing.')
        ->assertMissing('[data-turn-tool]')
        ->assertAttribute('[data-tool-lines-toggle]', 'aria-pressed', 'false')
        ->click('[data-tool-lines-toggle]')
        ->assertPresent('[data-turn-tool="playback"]');
});

test('routing off shows all tools', function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(false);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-tool-all]')
        ->assertMissing('[data-tool-add]');
});

test('a pinned group whose integration is gone shows as unavailable', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = chatToolGroupsConversation($admin, ['indexers']);
    $this->actingAs($admin);

    $webpage = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertPresent('[data-tool-chip="indexers"][data-tool-unavailable]')
        ->assertAttribute('[data-tool-chip="indexers"]', 'title', "Not available — its integration isn't configured")
        ->click('[data-tool-unpin="indexers"]')
        ->assertMissing('[data-tool-chip="indexers"]');
});

test('tool strip controls are disabled while a reply streams', function (): void {
    ServiceConnection::factory()->prowlarr()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertEnabled('[data-tool-add]')
        ->click('[data-tool-add]')
        ->click('[data-tool-pin="indexers"]')
        ->assertEnabled('[data-tool-unpin="indexers"]');
    $webpage->script(chatToolGroupsHoldStreamScript());
    $webpage->fill('[data-chat-input]', 'Hi')
        ->keys('[data-chat-input]', 'Enter')
        ->assertSeeIn('[data-chat-thread]', 'thinking…')
        ->assertDisabled('[data-tool-add]')
        ->assertDisabled('[data-tool-unpin="indexers"]');
});

test('pinning in an existing conversation is saved and survives a reload', function (): void {
    ServiceConnection::factory()->prowlarr()->create();
    $admin = User::factory()->admin()->create();
    $conversationId = chatToolGroupsConversation($admin, []);
    $this->actingAs($admin);

    $webpage = visit(route('ai.chat', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertEnabled('[data-tool-add]')
        ->click('[data-tool-add]')
        ->click('[data-tool-pin="indexers"]')
        ->assertPresent('[data-tool-chip="indexers"][data-tool-source="pinned"]');

    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-tool-unpin=\"indexers\"]')?.disabled === false"));

    expect(json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('pinned_tool_groups'), true))->toBe(['indexers']);

    $reloaded = $webpage->refresh()->assertNoSmoke();
    $reloaded->script(appHydratedScript());
    $reloaded->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertPresent('[data-tool-chip="indexers"][data-tool-source="pinned"]');
});

test('the admin transcript shows each reply\'s tools', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = chatToolGroupsConversation($admin, [], withReply: true);
    $this->actingAs($admin);

    visit(route('admin.ai-conversations.show', $conversationId, absolute: false))
        ->assertNoSmoke()
        ->assertSee('Stored reply')
        ->assertPresent('[data-turn-tool="playback"][data-turn-tool-source="picked"]');
});
