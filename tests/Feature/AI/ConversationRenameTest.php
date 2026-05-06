<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function seedRenameConversation(int $userId): string
{
    $id = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $userId,
        'title' => 'Original title',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    return $id;
}

test('owner can rename their conversation', function (): void {
    $admin = User::factory()->admin()->create();
    $id = seedRenameConversation($admin->id);

    $response = $this->actingAs($admin)
        ->patchJson(route('ai.conversations.rename', ['conversation' => $id]), [
            'title' => 'Sonarr cleanup',
        ])
        ->assertOk();

    expect($response->json('title'))->toBe('Sonarr cleanup');

    $row = DB::table('agent_conversations')->where('id', $id)->first();
    expect($row->title)->toBe('Sonarr cleanup');
    expect($row->updated_at)->not->toBe($row->created_at);
});

test('renaming foreign conversation returns 404', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $id = seedRenameConversation($other->id);

    $this->actingAs($admin)
        ->patchJson(route('ai.conversations.rename', ['conversation' => $id]), [
            'title' => 'Trying to take over',
        ])
        ->assertNotFound();

    $row = DB::table('agent_conversations')->where('id', $id)->first();
    expect($row->title)->toBe('Original title');
});

test('blank title is rejected', function (): void {
    $admin = User::factory()->admin()->create();
    $id = seedRenameConversation($admin->id);

    $this->actingAs($admin)
        ->patchJson(route('ai.conversations.rename', ['conversation' => $id]), [
            'title' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
});

test('title longer than 120 chars is rejected', function (): void {
    $admin = User::factory()->admin()->create();
    $id = seedRenameConversation($admin->id);

    $this->actingAs($admin)
        ->patchJson(route('ai.conversations.rename', ['conversation' => $id]), [
            'title' => str_repeat('x', 121),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
});

test('returns 404 when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    $admin = User::factory()->admin()->create();
    $id = (string) Str::uuid();

    $this->actingAs($admin)
        ->patchJson(route('ai.conversations.rename', ['conversation' => $id]), [
            'title' => 'New title',
        ])
        ->assertNotFound();
});
