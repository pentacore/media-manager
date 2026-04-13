<?php

use App\Enums\UserRole;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
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
