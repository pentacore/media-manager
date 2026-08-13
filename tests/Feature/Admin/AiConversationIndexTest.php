<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

function adminInsertConvo(int $userId, string $title, ?string $archivedAt = null, ?string $updatedAt = null): string
{
    $id = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => User::class,
        'participant_id' => $userId,
        'title' => $title,
        'archived_at' => $archivedAt,
        'created_at' => $updatedAt ?? now(),
        'updated_at' => $updatedAt ?? now(),
    ]);

    return $id;
}

test('non-admin cannot view admin conversations index', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('admin.ai-conversations.index'))
        ->assertForbidden();
});

test('admin sees all conversations across users', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->member()->create();

    adminInsertConvo($admin->id, 'Admin convo');
    adminInsertConvo($other->id, 'Member convo');

    $this->actingAs($admin)
        ->get(route('admin.ai-conversations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiConversations/Index')
            ->has('conversations.data', 2)
        );
});

test('state filter active hides archived conversations', function (): void {
    $admin = User::factory()->admin()->create();
    adminInsertConvo($admin->id, 'Active convo');
    adminInsertConvo($admin->id, 'Archived convo', archivedAt: now()->toDateTimeString());

    $response = $this->actingAs($admin)
        ->get(route('admin.ai-conversations.index', ['state' => 'active']))
        ->assertOk();

    $titles = collect($response->viewData('page')['props']['conversations']['data'])
        ->pluck('title')
        ->all();

    expect($titles)->toContain('Active convo')->not->toContain('Archived convo');
});

test('state filter archived shows only archived conversations', function (): void {
    $admin = User::factory()->admin()->create();
    adminInsertConvo($admin->id, 'Active convo');
    adminInsertConvo($admin->id, 'Archived convo', archivedAt: now()->toDateTimeString());

    $response = $this->actingAs($admin)
        ->get(route('admin.ai-conversations.index', ['state' => 'archived']))
        ->assertOk();

    $titles = collect($response->viewData('page')['props']['conversations']['data'])
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Archived convo']);
});

test('user_id filter narrows results', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->member()->create();
    adminInsertConvo($admin->id, 'Admin convo');
    adminInsertConvo($other->id, 'Member convo');

    $response = $this->actingAs($admin)
        ->get(route('admin.ai-conversations.index', ['user_id' => $other->id]))
        ->assertOk();

    $titles = collect($response->viewData('page')['props']['conversations']['data'])
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Member convo']);
});

test('search filter matches title fragment', function (): void {
    $admin = User::factory()->admin()->create();
    adminInsertConvo($admin->id, 'Sonarr cleanup');
    adminInsertConvo($admin->id, 'Radarr audit');

    $response = $this->actingAs($admin)
        ->get(route('admin.ai-conversations.index', ['q' => 'Sonarr']))
        ->assertOk();

    $titles = collect($response->viewData('page')['props']['conversations']['data'])
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Sonarr cleanup']);
});
