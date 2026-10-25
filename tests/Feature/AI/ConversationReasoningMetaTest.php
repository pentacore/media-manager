<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::High)->create();
});

/**
 * Insert a conversation owned by $user holding the given [role, content, meta, steps] rows, one second apart.
 *
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>, 3: list<array<string, mixed>>}>  $rows
 */
function reasoningMetaConversation(User $user, array $rows): string
{
    $conversationId = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Old conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($rows as $position => [$role, $content, $meta, $steps]) {
        $createdAt = now()->addSeconds($position);
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7($createdAt),
            'conversation_id' => $conversationId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => MediaAgent::class,
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'usage' => '[]',
            'meta' => json_encode($meta),
            'steps' => json_encode($steps),
            'status' => 'completed',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    return $conversationId;
}

test('assistant messages record the reasoning level they ran at', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(route('ai.chat.send'), ['message' => 'Hi'])->assertOk()
        ->assertJsonPath('answered_by.provider', 'openai')
        ->assertJsonPath('answered_by.model', 'gpt-5.6-luna')
        ->assertJsonPath('answered_by.reasoning_label', 'High');

    $meta = json_decode((string) DB::table('agent_conversation_messages')
        ->where('conversation_id', $response->json('conversation_id'))
        ->where('role', 'assistant')
        ->value('meta'), true);

    expect($meta['reasoning_level'])->toBe('high')
        ->and($meta['model'])->toBe('gpt-5.6-luna');
});

test('the conversation shows who answered each message', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();
    $id = $this->actingAs($admin)->postJson(route('ai.chat.send'), ['message' => 'Hi'])->json('conversation_id');

    $this->actingAs($admin)->getJson(route('ai.conversations.show', $id))
        ->assertOk()
        ->assertJsonPath('messages.0.answered_by', null)
        ->assertJsonPath('messages.1.answered_by.provider', 'openai')
        ->assertJsonPath('messages.1.answered_by.model', 'gpt-5.6-luna')
        ->assertJsonPath('messages.1.answered_by.reasoning_label', 'High');
});

test('the conversation exposes message ids and the invocation id of each reply', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();
    $id = $this->actingAs($admin)->postJson(route('ai.chat.send'), ['message' => 'Hi'])
        ->assertJsonPath('invocation_id', fn ($value): bool => is_string($value) && $value !== '')
        ->json('conversation_id');

    $stored = DB::table('agent_conversation_messages')->where('conversation_id', $id)->where('role', 'assistant')->first();
    $invocationId = json_decode((string) $stored->meta, true)['invocation_id'];

    $this->actingAs($admin)->getJson(route('ai.conversations.show', $id))
        ->assertOk()
        ->assertJsonPath('messages.0.invocation_id', null)
        ->assertJsonPath('messages.1.id', $stored->id)
        ->assertJsonPath('messages.1.invocation_id', $invocationId);
});

test('a reply stored without an invocation id exposes null', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = reasoningMetaConversation($admin, [
        ['user', 'Hello', [], []],
        ['assistant', 'Hi there', ['provider' => 'openai', 'model' => 'gpt-5'], []],
    ]);

    $this->actingAs($admin)->getJson(route('ai.conversations.show', $conversationId))
        ->assertOk()
        ->assertJsonPath('messages.1.invocation_id', null)
        ->assertJsonPath('messages.1.id', DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->value('id'));
});

test('messages stored before the label existed show only provider and model', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = reasoningMetaConversation($admin, [
        ['user', 'Hello', [], []],
        ['assistant', 'Hi there', ['provider' => 'openai', 'model' => 'gpt-5'], [
            ['content' => 'Hi there', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
        ]],
    ]);

    $this->actingAs($admin)->getJson(route('ai.conversations.show', $conversationId))
        ->assertOk()
        ->assertJsonPath('messages.1.answered_by.provider', 'openai')
        ->assertJsonPath('messages.1.answered_by.model', 'gpt-5')
        ->assertJsonPath('messages.1.answered_by.reasoning_label', null);
});

test('the chat agent replays stored history adapted for the provider it runs on', function (): void {
    AiModelPrice::factory()->create(['provider' => 'gemini', 'model' => 'gemini-3-pro']);
    AiTaskModel::query()->where('task', AiTask::Chat)->update(['provider' => 'gemini', 'model' => 'gemini-3-pro']);
    $admin = User::factory()->admin()->create();
    $conversationId = reasoningMetaConversation($admin, [
        ['user', 'Find it', [], []],
        ['assistant', 'Found it.', ['provider' => 'gemini', 'model' => 'gemini-3-pro'], [
            ['content' => '', 'tool_calls' => [['id' => 'fc_1', 'name' => 'get_media', 'arguments' => ['id' => 1], 'result_id' => 'fc_1', 'thought_signature' => 'sig-abc', 'result' => 'ok']], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
            ['content' => 'Found it.', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
        ]],
    ]);

    $toolCalls = collect((new MediaAgent)->continue($conversationId, as: $admin)->messages())
        ->filter(fn (mixed $message): bool => $message instanceof AssistantMessage)
        ->flatMap(fn (AssistantMessage $message): array => $message->toolCalls->all());

    expect($toolCalls)->toHaveCount(1)
        ->and($toolCalls->first()->id)->toBe('fc_1')
        ->and($toolCalls->first()->thoughtSignature)->toBeNull();
});

test('the chat agent replays stored history valid for the failover provider too', function (string $primary, string $failover, bool $shortIds): void {
    AiTaskModel::query()->where('task', AiTask::Chat)->update(['provider' => $primary, 'model' => 'primary-model']);
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => $failover])->create();
    $admin = User::factory()->admin()->create();
    $conversationId = reasoningMetaConversation($admin, [
        ['user', 'Find it', [], []],
        ['assistant', 'Found it.', ['provider' => 'gemini', 'model' => 'gemini-3-pro'], [
            ['content' => '', 'tool_calls' => [['id' => 'fc_1', 'name' => 'get_media', 'arguments' => ['id' => 1], 'result_id' => 'call_1', 'thought_signature' => 'sig-abc', 'result' => 'ok']], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
            ['content' => 'Found it.', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
        ]],
    ]);

    $messages = collect((new MediaAgent)->continue($conversationId, as: $admin)->messages());
    $toolCall = $messages->first(fn (mixed $message): bool => $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty())->toolCalls->first();
    $toolResult = $messages->first(fn (mixed $message): bool => $message instanceof ToolResultMessage)->toolResults->first();

    expect($toolCall->thoughtSignature)->toBeNull()
        ->and($toolResult->id)->toBe($toolCall->id)
        ->and($toolResult->resultId)->toBe($toolCall->resultId)
        ->and($toolCall->id)->toBe($shortIds ? substr(hash('sha256', 'fc_1'), 0, 9) : 'fc_1')
        ->and($toolCall->resultId)->toBe($shortIds ? substr(hash('sha256', 'call_1'), 0, 9) : 'call_1');
})->with([
    'gemini primary, openai failover' => ['gemini', 'openai', false],
    'openai primary, gemini failover' => ['openai', 'gemini', false],
    'openai primary, mistral failover' => ['openai', 'mistral', true],
    'gemini primary, mistral failover' => ['gemini', 'mistral', true],
    'mistral primary, gemini failover' => ['mistral', 'gemini', true],
]);

test('assistant messages record the chat mode they ran in', function (): void {
    MediaAgent::fake(['Hello']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(route('ai.chat.send'), ['message' => 'Hi', 'mode' => 'advisory'])->assertOk();

    $meta = json_decode((string) DB::table('agent_conversation_messages')
        ->where('conversation_id', $response->json('conversation_id'))
        ->where('role', 'assistant')
        ->value('meta'), true);

    expect($meta['mode'])->toBe('advisory');
});
