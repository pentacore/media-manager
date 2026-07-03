<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function archiveConvoSeed(int $userId): string
{
    $id = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $userId,
        'title' => 'Archive me',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('non-admin cannot archive', function (): void {
    $member = User::factory()->member()->create();
    $admin = User::factory()->admin()->create();
    $id = archiveConvoSeed($admin->id);

    $this->actingAs($member)
        ->post(route('admin.ai-conversations.archive', ['conversation' => $id]))
        ->assertForbidden();
});

test('admin archive sets archived_at', function (): void {
    $admin = User::factory()->admin()->create();
    $id = archiveConvoSeed($admin->id);

    $this->actingAs($admin)
        ->post(route('admin.ai-conversations.archive', ['conversation' => $id]))
        ->assertRedirect();

    expect(DB::table('agent_conversations')->where('id', $id)->value('archived_at'))
        ->not->toBeNull();
});

test('admin unarchive clears archived_at', function (): void {
    $admin = User::factory()->admin()->create();
    $id = archiveConvoSeed($admin->id);
    DB::table('agent_conversations')->where('id', $id)->update(['archived_at' => now()]);

    $this->actingAs($admin)
        ->post(route('admin.ai-conversations.unarchive', ['conversation' => $id]))
        ->assertRedirect();

    expect(DB::table('agent_conversations')->where('id', $id)->value('archived_at'))
        ->toBeNull();
});

test('owner cannot post to archived conversation via /ai/chat', function (): void {
    MediaAgent::fake(['ok']);
    $admin = User::factory()->admin()->create();
    $id = archiveConvoSeed($admin->id);
    DB::table('agent_conversations')->where('id', $id)->update(['archived_at' => now()]);

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Continue',
            'conversation_id' => $id,
        ])
        ->assertNotFound();

    MediaAgent::assertNeverPrompted();
});
