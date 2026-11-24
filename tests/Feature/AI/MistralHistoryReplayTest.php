<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'mistral');
    config()->set('ai.providers.mistral.key', 'mistral-test');
    AiModelPrice::factory()->create(['provider' => 'mistral', 'model' => 'mistral-medium-latest']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('mistral', 'mistral-medium-latest')->create();
});

/**
 * A conversation whose earlier turn called a tool, stored with the long ids
 * another provider (Gemini) gave the call and its result.
 */
function mistralReplayConversation(User $user): string
{
    $conversationId = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Earlier lookup',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = [
        ['user', 'Find Severance', [], []],
        ['assistant', 'Found it.', ['provider' => 'gemini', 'model' => 'gemini-3-pro'], [
            ['content' => '', 'tool_calls' => [['id' => 'function-call-1234567890', 'name' => 'get_media', 'arguments' => ['id' => 1], 'result_id' => 'call_abcdefghijklmnop', 'thought_signature' => 'sig-abc', 'result' => 'ok']], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
            ['content' => 'Found it.', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
        ]],
    ];

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

test('a stored tool call reaches Mistral with a nine-character id its tool result answers', function (): void {
    Http::fake([
        'api.mistral.ai/*' => Http::response([
            'id' => 'cmpl-1',
            'object' => 'chat.completion',
            'model' => 'mistral-medium-latest',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'It is on the server.']]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25],
        ]),
    ]);
    $admin = User::factory()->admin()->create();
    $conversationId = mistralReplayConversation($admin);

    $response = (new MediaAgent)->continue($conversationId, as: $admin)->prompt('Is it on the server?');

    expect($response->text)->toBe('It is on the server.');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $messages = collect($request['messages']);
        $toolCallIds = $messages->where('role', 'assistant')->flatMap(fn (array $message): array => array_column($message['tool_calls'] ?? [], 'id'))->values();
        $toolResultIds = $messages->where('role', 'tool')->pluck('tool_call_id')->values();

        expect($toolCallIds)->toHaveCount(1)
            ->and($toolCallIds->first())->toMatch('/^[a-zA-Z0-9]{9}$/')
            ->and($toolResultIds->all())->toBe($toolCallIds->all());

        return str_starts_with($request->url(), 'https://api.mistral.ai/v1/');
    });
});
