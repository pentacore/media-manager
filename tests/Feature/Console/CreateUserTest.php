<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates a user with all options provided', function (): void {
    $this->artisan('users:create', [
        '--name' => 'Alice Example',
        '--email' => 'alice@example.com',
        '--password' => 'CorrectHorseBatteryStaple',
        '--role' => 'admin',
    ])->assertSuccessful();

    $user = User::firstWhere('email', 'alice@example.com');

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Alice Example')
        ->and($user->role)->toBe(UserRole::Admin)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->invite_accepted_at)->not->toBeNull()
        ->and(Hash::check('CorrectHorseBatteryStaple', $user->password))->toBeTrue();
});

test('rejects duplicate email', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('users:create', [
        '--name' => 'Bob',
        '--email' => 'taken@example.com',
        '--password' => 'CorrectHorseBatteryStaple',
        '--role' => 'member',
    ])->assertFailed();
});

test('rejects invalid email format', function (): void {
    $this->artisan('users:create', [
        '--name' => 'Bob',
        '--email' => 'not-an-email',
        '--password' => 'CorrectHorseBatteryStaple',
        '--role' => 'member',
    ])->assertFailed();
});

test('rejects weak password', function (): void {
    $this->artisan('users:create', [
        '--name' => 'Bob',
        '--email' => 'bob@example.com',
        '--password' => 'short',
        '--role' => 'member',
    ])->assertFailed();

    expect(User::firstWhere('email', 'bob@example.com'))->toBeNull();
});

test('defaults role to Member when invalid role given', function (): void {
    $this->artisan('users:create', [
        '--name' => 'Carol',
        '--email' => 'carol@example.com',
        '--password' => 'CorrectHorseBatteryStaple',
        '--role' => 'bogus',
    ])
        ->expectsQuestion('Role', UserRole::Member->value)
        ->assertSuccessful();

    expect(User::firstWhere('email', 'carol@example.com')->role)->toBe(UserRole::Member);
});
