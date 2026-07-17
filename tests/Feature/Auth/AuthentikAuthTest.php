<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function mockSocialiteUser(string $id = 'auth-123', string $email = 'sso@example.com', string $name = 'SSO User', ?string $avatar = null, bool $emailVerified = true): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = $id;
    $socialiteUser->email = $email;
    $socialiteUser->name = $name;
    $socialiteUser->avatar = $avatar;
    $socialiteUser->user = ['email_verified' => $emailVerified];

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
    expect($user->email_verified_at)->not->toBeNull(); // SSO bypasses email verification
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

test('authentik callback verifies email of linked unverified user', function (): void {
    $existing = User::factory()->unverified()->create(['email' => 'unverified@example.com']);
    expect($existing->email_verified_at)->toBeNull();

    mockSocialiteUser(id: 'auth-unverified', email: 'unverified@example.com', name: 'Linked');

    $this->get(route('auth.authentik.callback'));

    $this->assertAuthenticated();

    $existing->refresh();
    expect($existing->sso_provider)->toBe('authentik');
    expect($existing->email_verified_at)->not->toBeNull();
});

test('authentik callback refuses to link an existing account when the IdP email is unverified', function (): void {
    $existing = User::factory()->create(['email' => 'victim@example.com']);

    mockSocialiteUser(id: 'auth-takeover', email: 'victim@example.com', name: 'Attacker', emailVerified: false);

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->assertGuest();

    $existing->refresh();
    expect($existing->sso_provider)->toBeNull()
        ->and($existing->sso_id)->toBeNull();
});

test('authentik callback creates a new user without verified email when the claim is absent', function (): void {
    mockSocialiteUser(id: 'auth-nv', email: 'newbie@example.com', name: 'Newbie', emailVerified: false);

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'newbie@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull();
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

test('sso provider identities must be unique', function (): void {
    User::factory()->create([
        'sso_provider' => 'authentik',
        'sso_id' => 'duplicate-provider-id',
    ]);

    expect(fn () => User::factory()->create([
        'sso_provider' => 'authentik',
        'sso_id' => 'duplicate-provider-id',
    ]))->toThrow(QueryException::class);
});

test('authentik callback handles provider error gracefully', function (): void {
    Socialite::shouldReceive('driver->user')->andThrow(new Exception('Provider error'));

    $this->get(route('auth.authentik.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
});
