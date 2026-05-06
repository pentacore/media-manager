<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function seedConversationWithMessages(int $userId, ?string $archivedAt = null): string
{
    $conversationId = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $userId,
        'title' => 'A conversation',
        'archived_at' => $archivedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => MediaAgent::class,
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => MediaAgent::class,
            'role' => 'assistant',
            'content' => 'Hi there',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ],
    ]);

    return $conversationId;
}

test('owner can fetch their conversation with messages', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin->id);

    $response = $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertOk();

    $messages = $response->json('messages');
    expect($messages)->toHaveCount(2);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['text'])->toBe('Hello');
    expect($messages[1]['role'])->toBe('assistant');
});

test('foreign conversation returns 404', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($other->id);

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertNotFound();
});

test('archived conversation returns 404 for owner via user route', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = seedConversationWithMessages($admin->id, archivedAt: now()->toDateTimeString());

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', ['conversation' => $conversationId]))
        ->assertNotFound();
});
