<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Storage\DatabaseConversationStore;

test('the bound conversation store is the stock 1.0 database store', function (): void {
    expect(resolve(ConversationStore::class))->toBeInstanceOf(DatabaseConversationStore::class);
});

test('a failed turn with an unanswered call replays with an interrupted result', function (): void {
    $user = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'title' => 'T', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => MediaAgent::class, 'role' => 'assistant', 'content' => '', 'attachments' => '[]',
        'steps' => json_encode([['content' => '', 'tool_calls' => [['id' => 'call_x', 'name' => 'SearchMediaTool', 'arguments' => []]], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
        'usage' => '{}', 'meta' => json_encode(['error' => 'boom']), 'status' => 'failed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $messages = resolve(ConversationStore::class)->getLatestConversationMessages($conversationId, 10)->values();

    expect($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->first()->id)->toBe('call_x');
});

/**
 * Seed user, assistant (with the given status, meta and steps), user and
 * return the conversation's rebuilt history.
 *
 * @param  array<string, mixed>  $meta
 * @param  list<array<string, mixed>>  $steps
 * @return list<mixed>
 */
function replayAfterTurn(string $status, array $meta, array $steps): array
{
    $user = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'title' => 'T', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $base = [
        'conversation_id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => MediaAgent::class, 'attachments' => '[]', 'usage' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('agent_conversation_messages')->insert([
        [...$base, 'id' => (string) Str::uuid7(), 'role' => 'user', 'content' => 'Find Dune', 'steps' => '[]', 'meta' => '{}', 'status' => 'completed'],
        [...$base, 'id' => (string) Str::uuid7(), 'role' => 'assistant', 'content' => '', 'steps' => json_encode($steps), 'meta' => json_encode($meta), 'status' => $status],
        [...$base, 'id' => (string) Str::uuid7(), 'role' => 'user', 'content' => 'Try again', 'steps' => '[]', 'meta' => '{}', 'status' => 'completed'],
    ]);

    return resolve(ConversationStore::class)->getLatestConversationMessages($conversationId, 10)->values()->all();
}

test('an empty stopped turn replays as a placeholder reply so two user messages never follow each other', function (): void {
    $messages = replayAfterTurn('completed', ['stopped' => true], [
        ['content' => '', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
    ]);

    expect(array_map(static fn (mixed $message): string => $message->role->value, $messages))->toBe(['user', 'assistant', 'user'])
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[1]->content)->toBe('(The user stopped this reply.)');
});

test('an empty failed turn replays as a placeholder reply', function (): void {
    $messages = replayAfterTurn('failed', ['error' => 'boom'], []);

    expect(array_map(static fn (mixed $message): string => $message->role->value, $messages))->toBe(['user', 'assistant', 'user'])
        ->and($messages[1]->content)->toBe('(This reply failed.)');
});

test('a stopped turn with content replays its content, not the placeholder', function (): void {
    $messages = replayAfterTurn('completed', ['stopped' => true], [
        ['content' => 'Searching for Du', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
    ]);

    expect($messages)->toHaveCount(3)
        ->and($messages[1]->content)->toBe('Searching for Du');
});
