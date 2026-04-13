<?php

use App\Actions\FindOrCreateSsoUser;
use App\Enums\UserRole;
use App\Models\User;

test('first user created via SSO gets admin role', function (): void {
    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-123',
        email: 'first@example.com',
        name: 'First User',
    );

    expect($user->role)->toBe(UserRole::Admin);
});

test('second user created via SSO gets viewer role', function (): void {
    User::factory()->admin()->create();

    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-456',
        email: 'second@example.com',
        name: 'Second User',
    );

    expect($user->role)->toBe(UserRole::Viewer);
});

test('SSO login links existing user by email', function (): void {
    $existing = User::factory()->create(['email' => 'match@example.com']);

    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-789',
        email: 'match@example.com',
        name: 'Match User',
    );

    expect($user->id)->toBe($existing->id);
    expect($user->sso_provider)->toBe('authentik');
    expect($user->sso_id)->toBe('auth-789');
});

test('SSO login finds returning user by sso_id', function (): void {
    $existing = User::factory()->create([
        'sso_provider' => 'authentik',
        'sso_id' => 'auth-returning',
    ]);

    $action = new FindOrCreateSsoUser();

    $user = $action->execute(
        provider: 'authentik',
        ssoId: 'auth-returning',
        email: 'different@example.com',
        name: 'Different Name',
    );

    expect($user->id)->toBe($existing->id);
    expect(User::count())->toBe(1);
});

test('first user registered via Fortify gets admin role', function (): void {
    $this->post(route('register'), [
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $user = User::first();
    expect($user->role)->toBe(UserRole::Admin);
});

test('second user registered via Fortify gets viewer role', function (): void {
    User::factory()->admin()->create();

    $this->post(route('register'), [
        'name' => 'Viewer User',
        'email' => 'viewer@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $user = User::where('email', 'viewer@example.com')->first();
    expect($user->role)->toBe(UserRole::Viewer);
});
