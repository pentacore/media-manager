<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests cannot access user management', function (): void {
    $this->get(route('admin.users.index'))
        ->assertRedirect(route('login'));
});

test('non-admin cannot access user management', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('admin can list users', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->member()->create();
    User::factory()->create(); // viewer

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users', 3)
        );
});

test('admin can change user role', function (): void {
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update-role', $viewer), [
            'role' => 'member',
        ])
        ->assertRedirect(route('admin.users.index'));

    $viewer->refresh();
    expect($viewer->role)->toBe(UserRole::Member);
});

test('admin cannot change own role', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update-role', $admin), [
            'role' => 'viewer',
        ])
        ->assertForbidden();
});

test('role validation rejects invalid roles', function (): void {
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update-role', $viewer), [
            'role' => 'superadmin',
        ])
        ->assertSessionHasErrors('role');
});

test('admin can delete user', function (): void {
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $viewer))
        ->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseMissing('users', ['id' => $viewer->id]);
});

test('admin cannot delete themselves', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();
});
