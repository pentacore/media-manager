<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function deleteConvoSeed(int $userId): string
{
    $id = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => User::class,
        'participant_id' => $userId,
        'title' => 'Delete me',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $id,
        'participant_type' => User::class,
        'participant_id' => $userId,
        'agent' => MediaAgent::class,
        'role' => 'user',
        'content' => 'first message',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('non-admin cannot delete', function (): void {
    $member = User::factory()->member()->create();
    $admin = User::factory()->admin()->create();
    $id = deleteConvoSeed($admin->id);

    $this->actingAs($member)
        ->delete(route('admin.ai-conversations.destroy', ['conversation' => $id]))
        ->assertForbidden();

    expect(DB::table('agent_conversations')->where('id', $id)->exists())->toBeTrue();
});

test('admin delete removes the conversation and its messages', function (): void {
    $admin = User::factory()->admin()->create();
    $id = deleteConvoSeed($admin->id);

    $this->actingAs($admin)
        ->delete(route('admin.ai-conversations.destroy', ['conversation' => $id]))
        ->assertRedirect(route('admin.ai-conversations.index'));

    expect(DB::table('agent_conversations')->where('id', $id)->exists())->toBeFalse();
    expect(DB::table('agent_conversation_messages')->where('conversation_id', $id)->exists())->toBeFalse();
});

test('owner posting to a deleted conversation receives 404', function (): void {
    MediaAgent::fake(['ok']);
    $admin = User::factory()->admin()->create();
    $id = deleteConvoSeed($admin->id);

    $this->actingAs($admin)
        ->delete(route('admin.ai-conversations.destroy', ['conversation' => $id]));

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Continue',
            'conversation_id' => $id,
        ])
        ->assertNotFound();

    MediaAgent::assertNeverPrompted();
});

test('returns 404 when deleting a non-existent conversation', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('admin.ai-conversations.destroy', ['conversation' => (string) Str::uuid()]))
        ->assertNotFound();
});
