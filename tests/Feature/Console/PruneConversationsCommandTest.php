<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\ChatAttachment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('local');
});

function pruneConversationsConversation(User $user, CarbonInterface $updatedAt): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'title' => 'Chat', 'created_at' => $updatedAt, 'updated_at' => $updatedAt,
    ]);

    return $conversationId;
}

function pruneConversationsMessage(string $conversationId, User $user, string $attachmentsJson = '[]'): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => MediaAgent::class, 'role' => 'user', 'content' => 'Hello', 'attachments' => $attachmentsJson,
        'steps' => '[]', 'usage' => '{}', 'meta' => '{}', 'status' => 'completed',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('conversations are kept forever by default', function (): void {
    $user = User::factory()->admin()->create();
    $conversationId = pruneConversationsConversation($user, now()->subYears(3));

    $this->artisan('ai:prune-conversations')->assertSuccessful();

    expect(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeTrue();
});

test('idle conversations past the window go with their messages and attachment files', function (): void {
    config()->set('mediamanager.retention.agent_conversations_days', 90);
    $user = User::factory()->admin()->create();
    $idle = pruneConversationsConversation($user, now()->subDays(120));
    $active = pruneConversationsConversation($user, now()->subDays(5));
    pruneConversationsMessage($idle, $user);
    pruneConversationsMessage($active, $user);
    $idleAttachment = ChatAttachment::factory()->create(['user_id' => $user->id, 'conversation_id' => $idle]);
    $activeAttachment = ChatAttachment::factory()->create(['user_id' => $user->id, 'conversation_id' => $active]);

    $this->artisan('ai:prune-conversations')
        ->expectsOutputToContain('Pruned 1 idle conversation(s)')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->pluck('id')->all())->toBe([$active])
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $idle)->exists())->toBeFalse()
        ->and(ChatAttachment::query()->whereKey($idleAttachment->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($idleAttachment->path))->toBeFalse()
        ->and(ChatAttachment::query()->whereKey($activeAttachment->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($activeAttachment->path))->toBeTrue();
});

test('the sweep removes attachments no conversation will show and keeps any a stored message references', function (): void {
    $user = User::factory()->admin()->create();
    $conversationId = pruneConversationsConversation($user, now());

    $ofDeletedConversation = ChatAttachment::factory()->create(['user_id' => $user->id, 'conversation_id' => (string) Str::uuid7()]);
    $unassignedStale = ChatAttachment::factory()->create(['user_id' => $user->id, 'conversation_id' => null]);
    $unassignedFresh = ChatAttachment::factory()->create(['user_id' => $user->id, 'conversation_id' => null]);
    $unassignedReferenced = ChatAttachment::factory()->create(['user_id' => $user->id, 'conversation_id' => null]);
    ChatAttachment::query()->whereKey([$unassignedStale->id, $unassignedReferenced->id])->update(['created_at' => now()->subDays(2)]);
    // The SDK stores attachment JSON with escaped slashes (json_encode default).
    pruneConversationsMessage($conversationId, $user, json_encode([['type' => 'stored-image', 'path' => $unassignedReferenced->path, 'disk' => 'local']]));

    $this->artisan('ai:prune-conversations')
        ->expectsOutputToContain('swept 2 orphaned attachment(s)')
        ->assertSuccessful();

    expect(ChatAttachment::query()->orderBy('id')->pluck('id')->all())->toBe([$unassignedFresh->id, $unassignedReferenced->id])
        ->and(Storage::disk('local')->exists($ofDeletedConversation->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($unassignedStale->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($unassignedReferenced->path))->toBeTrue();
});

test('conversation pruning is scheduled nightly', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'ai:prune-conversations'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 3 * * *')
        ->and($event->expiresAt)->toBe(60);
});

test('a stored message naming another file that only matches as a LIKE wildcard does not keep an orphaned attachment', function (): void {
    $user = User::factory()->admin()->create();
    $conversationId = pruneConversationsConversation($user, now());
    $wildcardNamed = ChatAttachment::factory()->create(['user_id' => $user->id, 'conversation_id' => null, 'path' => 'chat-attachments/scan_1.png']);
    ChatAttachment::query()->whereKey($wildcardNamed->id)->update(['created_at' => now()->subDays(2)]);
    // "scanX1.png" matches an unescaped LIKE '%scan_1.png%': `_` is a one-character wildcard.
    pruneConversationsMessage($conversationId, $user, json_encode([['type' => 'stored-image', 'path' => 'chat-attachments/scanX1.png', 'disk' => 'local']]));

    $this->artisan('ai:prune-conversations')
        ->expectsOutputToContain('swept 1 orphaned attachment(s)')
        ->assertSuccessful();

    expect(ChatAttachment::query()->whereKey($wildcardNamed->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('chat-attachments/scan_1.png'))->toBeFalse();
});
