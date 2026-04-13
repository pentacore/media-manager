<?php

use App\Enums\UserRole;
use App\Mail\UserInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

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

test('admin can invite a user', function (): void {
    Mail::fake();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'role' => 'member',
        ])
        ->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'newuser@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('New User');
    expect($user->role)->toBe(UserRole::Member);
    expect($user->password)->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();

    Mail::assertSent(UserInvitation::class, fn (UserInvitation $userInvitation) => $userInvitation->hasTo('newuser@example.com'));
});

test('invite validates required fields', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [])
        ->assertSessionHasErrors(['name', 'email', 'role']);
});

test('invite rejects duplicate email', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'role' => 'viewer',
        ])
        ->assertSessionHasErrors('email');
});

test('admin can create user with password directly', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Direct User',
            'email' => 'direct@example.com',
            'role' => 'member',
            'set_password' => true,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'direct@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->password)->not->toBeNull();
    expect($user->role)->toBe(UserRole::Member);
});

test('set_password requires password fields', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'No Pass',
            'email' => 'nopass@example.com',
            'role' => 'viewer',
            'set_password' => true,
        ])
        ->assertSessionHasErrors('password');
});

test('non-admin cannot invite users', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('admin.users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'role' => 'admin',
        ])
        ->assertForbidden();
});

test('invited user can accept invite and set password', function (): void {
    $user = User::factory()->create(['password' => null]);

    $url = URL::temporarySignedRoute('auth.invite.accept', now()->addHours(48), ['user' => $user->id]);

    $this->get($url)
        ->assertOk();

    $this->assertAuthenticated();

    $this->post(route('auth.set-password.store'), [
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->password)->not->toBeNull();
});

test('expired invite link is rejected', function (): void {
    $user = User::factory()->create(['password' => null]);

    $url = URL::temporarySignedRoute('auth.invite.accept', now()->subHour(), ['user' => $user->id]);

    $this->get($url)
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('users without password are redirected to set password', function (): void {
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('auth.set-password'));
});
