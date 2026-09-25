<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('admin transcript exposes steps with inline tool results and status', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $admin->getMorphClass(), 'participant_id' => $admin->id,
        'title' => 'T', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId,
        'participant_type' => $admin->getMorphClass(), 'participant_id' => $admin->id,
        'agent' => MediaAgent::class, 'role' => 'assistant', 'content' => 'Done', 'attachments' => '[]',
        'steps' => json_encode([['content' => 'Done', 'tool_calls' => [['id' => 'c1', 'name' => 'SearchMediaTool', 'arguments' => ['q' => 'x'], 'result' => '{"ok":true}']], 'reasoning' => 'why', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
        'usage' => '{}', 'meta' => json_encode(['error' => 'nope']), 'status' => 'failed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-conversations.show', $conversationId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/AiConversations/Show')
            ->where('messages.0.status', 'failed')
            ->where('messages.0.error', 'nope')
            ->where('messages.0.steps.0.reasoning', 'why')
            ->where('messages.0.steps.0.tool_calls.0.result', '{"ok":true}'));
});
