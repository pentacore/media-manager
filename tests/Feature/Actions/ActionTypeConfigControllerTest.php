<?php

declare(strict_types=1);

use App\Models\ActionTypeConfig;
use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('guests are redirected to login', function (): void {
    $this->get(route('actions.rules.index'))->assertRedirect(route('login'));
});

test('members cannot access rules index', function (): void {
    $member = User::factory()->member()->create();
    $this->actingAs($member)->get(route('actions.rules.index'))->assertForbidden();
});

test('admins can list rules', function (): void {
    $admin = User::factory()->admin()->create();
    ActionTypeConfig::factory()->count(4)->create();

    $this->actingAs($admin)
        ->get(route('actions.rules.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Actions/Rules')
            ->has('rules', 4)
        );
});

test('admins can update a rule', function (): void {
    $admin = User::factory()->admin()->create();
    $config = ActionTypeConfig::factory()->create([
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    $this->actingAs($admin)
        ->from(route('actions.rules.index'))
        ->patch(route('actions.rules.update', $config), [
            'requires_approval' => false,
            'is_enabled' => false,
        ])
        ->assertRedirect(route('actions.rules.index'));

    $config->refresh();
    expect($config->requires_approval)->toBeFalse();
    expect($config->is_enabled)->toBeFalse();
});

test('update validates required fields', function (): void {
    $admin = User::factory()->admin()->create();
    $config = ActionTypeConfig::factory()->create();

    $this->actingAs($admin)
        ->patch(route('actions.rules.update', $config), [])
        ->assertSessionHasErrors(['requires_approval', 'is_enabled']);
});

test('members cannot update rules', function (): void {
    $member = User::factory()->member()->create();
    $config = ActionTypeConfig::factory()->create();

    $this->actingAs($member)
        ->patch(route('actions.rules.update', $config), [
            'requires_approval' => false,
            'is_enabled' => true,
        ])
        ->assertForbidden();
});
