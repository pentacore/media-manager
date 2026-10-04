<?php

declare(strict_types=1);

use App\Models\ChatTemplate;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('members and viewers cannot reach templates', function (string $role): void {
    $user = $role === 'member' ? User::factory()->member()->create() : User::factory()->create();

    $this->actingAs($user)->get(route('ai.templates.index'))->assertForbidden();
    $this->actingAs($user)->get(route('ai.templates.create'))->assertForbidden();
    $this->actingAs($user)->post(route('ai.templates.store'), [])->assertForbidden();
})->with(['member', 'viewer']);

test('templates are unavailable while AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('ai.templates.index'))
        ->assertNotFound();
});

test('another admin\'s template is not found', function (string $method, string $routeName): void {
    $chatTemplate = ChatTemplate::factory()->create(['name' => 'Theirs']);

    $this->actingAs(User::factory()->admin()->create())
        ->json($method, route($routeName, $chatTemplate), [
            'name' => 'Mine now', 'body' => 'Hello', 'variables' => [], 'auto_send' => false, 'pinned' => false,
        ])
        ->assertNotFound();

    expect($chatTemplate->fresh())
        ->not->toBeNull()
        ->name->toBe('Theirs')
        ->pinned->toBeFalse();
})->with([
    'edit' => ['GET', 'ai.templates.edit'],
    'update' => ['PATCH', 'ai.templates.update'],
    'destroy' => ['DELETE', 'ai.templates.destroy'],
    'pin' => ['PATCH', 'ai.templates.pin'],
]);
