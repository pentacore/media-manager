<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function transcriptMessageRow(string $conversationId, User $user, int $position, array $attributes = []): array
{
    $createdAt = now()->addSeconds($position);

    return [
        'id' => (string) Str::uuid7($createdAt), 'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => MediaAgent::class, 'role' => $position % 2 === 0 ? 'user' : 'assistant',
        'content' => sprintf('Message %d', $position), 'attachments' => '[]', 'steps' => '[]',
        'usage' => '{}', 'meta' => '{}', 'status' => 'completed',
        'created_at' => $createdAt, 'updated_at' => $createdAt,
        ...$attributes,
    ];
}

function transcriptConversation(User $user, string $title): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert(['id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);

    return $conversationId;
}

test('admin transcript shows steps, reasoning, tool results and failed status', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = transcriptConversation($admin, 'Audit me');

    DB::table('agent_conversation_messages')->insert(transcriptMessageRow($conversationId, $admin, 1, [
        'content' => '',
        'steps' => json_encode([['content' => '', 'tool_calls' => [['id' => 'c1', 'name' => 'SearchMediaTool', 'arguments' => ['q' => 'Dune'], 'result' => '{"hits":1}']], 'reasoning' => 'Look it up first', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
        'meta' => json_encode(['error' => 'Provider returned 500.']),
        'status' => 'failed',
    ]));

    $this->actingAs($admin);

    visit(route('admin.ai-conversations.show', $conversationId, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-transcript-status]', 'failed')
        ->assertSeeIn('[data-transcript-error]', 'Provider returned 500.')
        ->assertSeeIn('[data-transcript-tool-call]', 'SearchMediaTool')
        ->assertSeeIn('[data-transcript-tool-call]', '{"hits":1}');
});

test('admin transcript loads older messages on demand', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = transcriptConversation($admin, 'Long transcript');

    DB::table('agent_conversation_messages')->insert(array_map(
        fn (int $position): array => transcriptMessageRow($conversationId, $admin, $position),
        range(0, 54),
    ));

    $this->actingAs($admin);

    visit(route('admin.ai-conversations.show', $conversationId, absolute: false))
        ->assertNoSmoke()
        ->assertSee('Message 54')
        ->assertDontSee('Message 0')
        ->click('[data-transcript-older] button')
        ->assertSee('Message 0')
        ->assertDontSee('Message 54')
        ->assertMissing('[data-transcript-older]');
});
