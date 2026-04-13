<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function mockSocialiteUser(string $id = 'auth-123', string $email = 'sso@example.com', string $name = 'SSO User', ?string $avatar = null): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = $id;
    $socialiteUser->email = $email;
    $socialiteUser->name = $name;
    $socialiteUser->avatar = $avatar;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
}

test('authentik redirect sends to provider', function (): void {
    Socialite::shouldReceive('driver->redirect')->once()->andReturn(redirect('https://authentik.example.com'));

    $this->get(route('auth.authentik'))
        ->assertRedirect();
});

test('authentik callback creates new user', function (): void {
    mockSocialiteUser();

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('email', 'sso@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->sso_provider)->toBe('authentik');
    expect($user->sso_id)->toBe('auth-123');
    expect($user->name)->toBe('SSO User');
    expect($user->role)->toBe(UserRole::Admin); // first user
});

test('authentik callback assigns viewer to non-first user', function (): void {
    User::factory()->admin()->create();

    mockSocialiteUser(id: 'auth-456', email: 'second@example.com', name: 'Second');

    $this->get(route('auth.authentik.callback'));

    $user = User::where('email', 'second@example.com')->first();
    expect($user->role)->toBe(UserRole::Viewer);
});

test('authentik callback links existing user by email', function (): void {
    $existing = User::factory()->create(['email' => 'existing@example.com']);

    mockSocialiteUser(id: 'auth-link', email: 'existing@example.com', name: 'Linked');

    $this->get(route('auth.authentik.callback'));

    $this->assertAuthenticated();

    $existing->refresh();
    expect($existing->sso_provider)->toBe('authentik');
    expect($existing->sso_id)->toBe('auth-link');
});

test('authentik callback logs in returning SSO user', function (): void {
    User::factory()->create([
        'sso_provider' => 'authentik',
        'sso_id' => 'auth-returning',
        'email' => 'returning@example.com',
    ]);

    mockSocialiteUser(id: 'auth-returning', email: 'returning@example.com');

    $this->get(route('auth.authentik.callback'));

    $this->assertAuthenticated();
    expect(User::count())->toBe(1);
});

test('authentik callback handles provider error gracefully', function (): void {
    Socialite::shouldReceive('driver->user')->andThrow(new Exception('Provider error'));

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
});
