<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Routing\ToolGroup;
use App\Models\User;
use App\Services\Chat\ConversationToolPins;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * Insert a conversation owned by $user, optionally archived or pre-pinned.
 */
function pinsConversation(User $user, ?string $pinnedJson = null, bool $archived = false): string
{
    $id = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Test',
        'pinned_tool_groups' => $pinnedJson,
        'archived_at' => $archived ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function storedPins(string $id): ?string
{
    return DB::table('agent_conversations')->where('id', $id)->value('pinned_tool_groups');
}

test("the owner can replace a conversation's pins", function (): void {
    $admin = User::factory()->admin()->create();
    $id = pinsConversation($admin, '["downloads"]');

    $this->actingAs($admin)
        ->putJson(route('ai.conversations.tool-groups', $id), ['groups' => ['indexers', 'playback']])
        ->assertOk()
        ->assertExactJson(['pinned_tool_groups' => ['indexers', 'playback']]);

    expect(json_decode((string) storedPins($id), true))->toBe(['indexers', 'playback']);
});

test("pinning someone else's conversation is not found", function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $id = pinsConversation($other, '["downloads"]');

    $this->actingAs($admin)
        ->putJson(route('ai.conversations.tool-groups', $id), ['groups' => ['indexers']])
        ->assertNotFound();

    expect(storedPins($id))->toBe('["downloads"]');
});

test('pinning an archived conversation is not found', function (): void {
    $admin = User::factory()->admin()->create();
    $id = pinsConversation($admin, '["downloads"]', archived: true);

    $this->actingAs($admin)
        ->putJson(route('ai.conversations.tool-groups', $id), ['groups' => ['indexers']])
        ->assertNotFound();

    expect(storedPins($id))->toBe('["downloads"]');
});

test('unknown groups are rejected', function (): void {
    $admin = User::factory()->admin()->create();
    $id = pinsConversation($admin);

    $this->actingAs($admin)
        ->putJson(route('ai.conversations.tool-groups', $id), ['groups' => ['nope']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('groups.0');
});

test('a new conversation keeps the pins sent with its first turn', function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(true);
    Classification::fake([toolPinsAnswers()]);
    MediaAgent::fake(['ok']);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.chat.send'), ['message' => 'Hello', 'pinned_tool_groups' => ['indexers']])
        ->assertOk();

    expect(DB::table('agent_conversations')->pluck('pinned_tool_groups')->all())->toBe(['["indexers"]']);
});

test('a new streamed conversation keeps the pins sent with its first turn', function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(true);
    Classification::fake([toolPinsAnswers()]);
    MediaAgent::fake(['ok']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('ai.chat.stream'), ['message' => 'Hello', 'pinned_tool_groups' => ['indexers']], ['Accept' => 'text/event-stream'])
        ->streamedContent();

    expect(DB::table('agent_conversations')->pluck('pinned_tool_groups')->all())->toBe(['["indexers"]']);
});

test('an unknown pinned group value is ignored', function (): void {
    $admin = User::factory()->admin()->create();
    $id = pinsConversation($admin, '["indexers","gone"]');

    expect(resolve(ConversationToolPins::class)->forConversation($id))->toBe([ToolGroup::Indexers]);

    resolve(AiSettings::class)->setChatRoutingEnabled(true);
    Classification::fake([toolPinsAnswers()]);
    MediaAgent::fake(['ok']);

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), ['message' => 'Hello', 'conversation_id' => $id])
        ->assertOk();
});

test('pins sent for an existing conversation are ignored in favour of its stored pins', function (): void {
    $admin = User::factory()->admin()->create();
    $id = pinsConversation($admin, '["playback"]');

    expect(resolve(ConversationToolPins::class)->forTurn($id, ['indexers']))->toBe([ToolGroup::Playback])
        ->and(resolve(ConversationToolPins::class)->forTurn(null, ['indexers', 'gone']))->toBe([ToolGroup::Indexers]);
});

test('the tool groups endpoint returns pins and carried-over groups', function (): void {
    $admin = User::factory()->admin()->create();
    $id = pinsConversation($admin, '["indexers"]');
    $createdAt = now();

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7($createdAt),
        'conversation_id' => $id,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'agent' => MediaAgent::class,
        'role' => 'assistant',
        'content' => 'Nothing.',
        'attachments' => '[]',
        'usage' => '[]',
        'meta' => '{}',
        'steps' => json_encode([['content' => 'Nothing.', 'tool_calls' => [['id' => 'c1', 'name' => 'NowPlayingTool', 'arguments' => [], 'result_id' => 'r1']], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
        'status' => 'completed',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.tool-groups.show', $id))
        ->assertOk()
        ->assertExactJson([
            'pinned_tool_groups' => ['indexers'],
            'carried_over_tool_groups' => [['group' => 'playback', 'label' => 'Playback', 'source' => 'carried_over']],
        ]);
});

test("the tool groups endpoint does not show someone else's conversation", function (): void {
    $admin = User::factory()->admin()->create();
    $id = pinsConversation(User::factory()->admin()->create());

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.tool-groups.show', $id))
        ->assertNotFound();
});

/**
 * @return array<string, BooleanAnswer>
 */
function toolPinsAnswers(): array
{
    return collect(ToolGroup::cases())
        ->mapWithKeys(fn (ToolGroup $toolGroup): array => [$toolGroup->value => new BooleanAnswer($toolGroup === ToolGroup::Playback ? 0.9 : 0.05)])
        ->all();
}
