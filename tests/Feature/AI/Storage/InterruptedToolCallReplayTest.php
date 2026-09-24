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
