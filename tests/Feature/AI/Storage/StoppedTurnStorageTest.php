<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Middleware\ClientDisconnectedException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\Prompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Store an assistant turn that died with the given exception and return its row.
 */
function storeTurnDiedWith(Throwable $throwable): object
{
    $user = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'title' => 'T', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, new MediaAgent);
    new ReflectionProperty(Prompt::class, 'prompt')->setValue($agentPrompt, 'hello');
    new ReflectionProperty(Prompt::class, 'approvalDecisions')->setValue($agentPrompt, null);
    new ReflectionProperty(AgentPrompt::class, 'attachments')->setValue($agentPrompt, collect());

    resolve(ConversationStore::class)->storeAssistantMessage(
        $conversationId,
        $user->getMorphClass(),
        $user->id,
        $agentPrompt,
        new AgentResponse('inv-1', '', new TextUsage, new Meta('openai', 'gpt-5-mini')),
        $throwable,
    );

    return DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();
}

test('a turn killed by a client disconnect is stored as a completed stopped turn without an error', function (): void {
    $row = storeTurnDiedWith(new ClientDisconnectedException);
    $meta = json_decode((string) $row->meta, true);

    expect($row->status)->toBe('completed')
        ->and($meta)->toHaveKey('stopped', true)
        ->and($meta)->not->toHaveKey('error');
});

test('any other failure is still stored as failed with its error', function (): void {
    $row = storeTurnDiedWith(new RuntimeException('boom'));
    $meta = json_decode((string) $row->meta, true);

    expect($row->status)->toBe('failed')
        ->and($meta)->toHaveKey('error', 'boom')
        ->and($meta)->not->toHaveKey('stopped');
});
