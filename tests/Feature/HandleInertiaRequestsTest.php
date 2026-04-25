<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('shared auth.user exposes only safe fields', function (): void {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'role' => UserRole::Admin,
        'password' => bcrypt('super-secret'),
    ]);
    $user->forceFill([
        'remember_token' => 'a-secret-remember-token',
    ])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', 'Test User')
            ->where('auth.user.email', 'test@example.com')
            ->where('auth.user.role', UserRole::Admin->value)
            ->has('auth.user.email_verified_at')
            ->has('auth.user.avatar_url')
            // Sensitive fields must not be present in the shared payload.
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes')
            ->missing('auth.user.two_factor_confirmed_at')
            ->missing('auth.user.sso_provider')
            ->missing('auth.user.sso_id')
        );
});

test('shared auth.user is null for guests', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user', null));
});
