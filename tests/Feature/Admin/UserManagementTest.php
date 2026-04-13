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

test('admin can create a local user', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'member',
        ])
        ->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'newuser@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('New User');
    expect($user->role)->toBe(UserRole::Member);
    expect($user->email_verified_at)->not->toBeNull();
});

test('create user validates required fields', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [])
        ->assertSessionHasErrors(['name', 'email', 'password', 'role']);
});

test('create user rejects duplicate email', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'viewer',
        ])
        ->assertSessionHasErrors('email');
});

test('non-admin cannot create users', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('admin.users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',
        ])
        ->assertForbidden();
});
