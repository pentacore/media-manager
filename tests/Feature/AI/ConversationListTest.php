<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function createConversation(int $userId, string $title = 'Test', ?string $archivedAt = null, ?string $updatedAt = null): string
{
    $id = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $userId,
        'title' => $title,
        'archived_at' => $archivedAt,
        'created_at' => $updatedAt ?? now(),
        'updated_at' => $updatedAt ?? now(),
    ]);

    return $id;
}

test('guest cannot list conversations', function (): void {
    $this->getJson(route('ai.conversations.index'))
        ->assertUnauthorized();
});

test('non-admin cannot list conversations', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->getJson(route('ai.conversations.index'))
        ->assertForbidden();
});

test('returns 404 when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.index'))
        ->assertNotFound();
});

test('admin sees only their own active conversations ordered by updated_at desc', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    $oldest = createConversation($admin->id, 'Oldest', updatedAt: now()->subDays(2)->toDateTimeString());
    $newest = createConversation($admin->id, 'Newest', updatedAt: now()->toDateTimeString());
    $archived = createConversation($admin->id, 'Archived hidden', archivedAt: now()->toDateTimeString());
    $foreign = createConversation($other->id, 'Foreign');

    $response = $this->actingAs($admin)
        ->getJson(route('ai.conversations.index'))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$newest, $oldest])
        ->not->toContain($archived)
        ->not->toContain($foreign);
});

test('list is capped at 20 entries', function (): void {
    $admin = User::factory()->admin()->create();

    for ($i = 0; $i < 25; $i++) {
        createConversation($admin->id, 'Convo '.$i, updatedAt: now()->subMinutes($i)->toDateTimeString());
    }

    $response = $this->actingAs($admin)
        ->getJson(route('ai.conversations.index'))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(20);
});
