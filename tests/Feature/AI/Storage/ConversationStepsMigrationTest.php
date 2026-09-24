<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;

function stepsMigration(): object
{
    return require database_path('migrations/2026_09_24_100000_store_agent_conversation_messages_as_steps.php');
}

/**
 * Roll the messages table back to the 0.10 shape and seed one tool turn.
 */
function seedLegacyToolTurn(User $user): string
{
    stepsMigration()->down();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'title' => 'Legacy', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $base = [
        'conversation_id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => MediaAgent::class, 'attachments' => '[]', 'usage' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('agent_conversation_messages')->insert([
        [...$base, 'id' => (string) Str::uuid7(), 'role' => 'user', 'content' => 'Find Dune', 'tool_calls' => '[]', 'tool_results' => '[]', 'meta' => '{}'],
        [...$base, 'id' => (string) Str::uuid7(), 'role' => 'assistant', 'content' => 'Found it.',
            'tool_calls' => json_encode([['id' => 'call_1', 'name' => 'SearchMediaTool', 'arguments' => ['q' => 'Dune']]]),
            'tool_results' => json_encode([['id' => 'call_1', 'name' => 'SearchMediaTool', 'arguments' => ['q' => 'Dune'], 'result' => '{"hits":1}']]),
            'meta' => json_encode(['provider' => 'openai', 'reasoning' => 'looked it up'])],
    ]);

    return $conversationId;
}

test('the migration rewrites legacy tool turns as steps', function (): void {
    $user = User::factory()->admin()->create();
    $conversationId = seedLegacyToolTurn($user);

    stepsMigration()->up();

    expect(Schema::hasColumns('agent_conversation_messages', ['steps', 'status']))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversation_messages', 'tool_calls'))->toBeFalse();

    $assistant = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->first();
    $steps = json_decode($assistant->steps, true);

    expect($assistant->status)->toBe('completed')
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]['tool_calls'][0]['result'])->toBe('{"hits":1}')
        ->and($steps[1]['content'])->toBe('Found it.')
        ->and($steps[1]['reasoning'])->toBe('looked it up');
});

test('a migrated conversation replays with every call answered', function (): void {
    $user = User::factory()->admin()->create();
    $conversationId = seedLegacyToolTurn($user);
    stepsMigration()->up();

    $messages = resolve(ConversationStore::class)->getLatestConversationMessages($conversationId, 50)->values();

    $assistantWithCalls = $messages->first(fn ($message): bool => $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty());
    $index = $messages->search($assistantWithCalls);

    expect($messages[$index + 1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[$index + 1]->toolResults->pluck('id')->all())->toBe(['call_1']);
});

test('down restores the legacy columns from steps', function (): void {
    $user = User::factory()->admin()->create();
    $conversationId = seedLegacyToolTurn($user);
    stepsMigration()->up();
    stepsMigration()->down();

    $assistant = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->first();

    expect(json_decode($assistant->tool_calls, true)[0]['id'])->toBe('call_1')
        ->and(json_decode($assistant->tool_results, true)[0]['result'])->toBe('{"hits":1}');

    stepsMigration()->up();
});
