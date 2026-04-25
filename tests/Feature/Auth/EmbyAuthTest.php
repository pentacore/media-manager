<?php

use App\Enums\UserRole;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->embyConnection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'emby-api-key',
    ]);
});

test('emby login succeeds with valid credentials', function (): void {
    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => [
                'Id' => 'emby-user-123',
                'Name' => 'EmbyUser',
            ],
            'AccessToken' => 'some-token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'EmbyUser',
        'password' => 'embypass',
        'email' => 'emby@example.com',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('email', 'emby@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('EmbyUser');
    expect($user->role)->toBe(UserRole::Admin); // first user

    expect(EmbyUserLink::where('user_id', $user->id)->where('emby_user_id', 'emby-user-123')->exists())->toBeTrue();
});

test('emby login with existing link skips email requirement', function (): void {
    $user = User::factory()->create();
    EmbyUserLink::factory()->create([
        'user_id' => $user->id,
        'emby_user_id' => 'emby-user-456',
        'emby_username' => 'ReturningUser',
    ]);

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => [
                'Id' => 'emby-user-456',
                'Name' => 'ReturningUser',
            ],
            'AccessToken' => 'some-token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'ReturningUser',
        'password' => 'pass',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
    expect(User::count())->toBe(1);
});

test('emby login fails with invalid credentials', function (): void {
    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([], 401),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'BadUser',
        'password' => 'wrongpass',
        'email' => 'bad@example.com',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');
});

test('emby login is rate limited', function (): void {
    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([], 401),
    ]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post(route('auth.emby'), [
            'username' => 'RateLimitedUser',
            'password' => 'wrongpass',
            'email' => 'rate-limited@example.com',
        ])->assertRedirect(route('login'));
    }

    $this->post(route('auth.emby'), [
        'username' => 'RateLimitedUser',
        'password' => 'wrongpass',
        'email' => 'rate-limited@example.com',
    ])->assertStatus(429);
});

test('emby login handles connection failures', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('connection refused'));

    $this->post(route('auth.emby'), [
        'username' => 'UnavailableUser',
        'password' => 'embypass',
        'email' => 'unavailable@example.com',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('emby login fails when no active emby connection exists', function (): void {
    $this->embyConnection->update(['is_active' => false]);

    $this->post(route('auth.emby'), [
        'username' => 'User',
        'password' => 'pass',
        'email' => 'user@example.com',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');
});

test('emby first login requires email for new users', function (): void {
    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'emby-new', 'Name' => 'NewUser'],
            'AccessToken' => 'token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'NewUser',
        'password' => 'pass',
        // no email
    ])->assertSessionHasErrors('email');
});

test('emby second user gets viewer role', function (): void {
    User::factory()->admin()->create();

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'emby-second', 'Name' => 'Second'],
            'AccessToken' => 'token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'Second',
        'password' => 'pass',
        'email' => 'second@example.com',
    ]);

    $user = User::where('email', 'second@example.com')->first();
    expect($user->role)->toBe(UserRole::Viewer);
});

test('emby login does not auto-link to existing local user by email match', function (): void {
    $victim = User::factory()->create([
        'email' => 'victim@example.com',
        'role' => UserRole::Admin,
    ]);

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'attacker-emby-id', 'Name' => 'Attacker'],
            'AccessToken' => 'token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'Attacker',
        'password' => 'pass',
        'email' => 'victim@example.com',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();

    expect(EmbyUserLink::where('emby_user_id', 'attacker-emby-id')->exists())->toBeFalse();
    expect(EmbyUserLink::where('user_id', $victim->id)->exists())->toBeFalse();
    expect(User::count())->toBe(1);
});

test('emby login with linked account logs in regardless of email field', function (): void {
    $user = User::factory()->create(['email' => 'stored@example.com']);
    EmbyUserLink::factory()->create([
        'user_id' => $user->id,
        'emby_user_id' => 'emby-linked-7',
        'emby_username' => 'LinkedUser',
    ]);

    Http::fake([
        'emby.local:8096/Users/AuthenticateByName' => Http::response([
            'User' => ['Id' => 'emby-linked-7', 'Name' => 'LinkedUser'],
            'AccessToken' => 'token',
        ]),
    ]);

    $this->post(route('auth.emby'), [
        'username' => 'LinkedUser',
        'password' => 'pass',
        // Even if an attacker spoofs some arbitrary email, the already-linked account wins.
        'email' => 'different@example.com',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
    expect(EmbyUserLink::count())->toBe(1);
});
