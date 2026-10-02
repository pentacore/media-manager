<?php

declare(strict_types=1);

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    // Without Authentik (or an active Emby connection) the login page renders
    // the local email form directly instead of behind a collapsible.
    config()->set('services.authentik.client_id');
});

function userWithAuthenticatorSecret(string $secret): User
{
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

test('a valid authenticator code typed into the OTP input completes the two-factor login', function (): void {
    $google2fa = resolve(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user = userWithAuthenticatorSecret($secret);

    visit(route('login', absolute: false))
        ->assertNoSmoke()
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs(route('two-factor.login', absolute: false))
        ->assertNoSmoke()
        ->type('[data-input-otp]', $google2fa->getCurrentOtp($secret))
        ->click('Continue')
        ->assertPathIs(route('dashboard', absolute: false));
});

test('an invalid authenticator code shows the error and clears the OTP input', function (): void {
    $google2fa = resolve(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user = userWithAuthenticatorSecret($secret);

    $invalidCode = $google2fa->getCurrentOtp($secret) === '000000' ? '111111' : '000000';

    visit(route('login', absolute: false))
        ->assertNoSmoke()
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs(route('two-factor.login', absolute: false))
        ->type('[data-input-otp]', $invalidCode)
        ->click('Continue')
        ->assertSee('The provided two factor authentication code was invalid.')
        ->assertPathIs(route('two-factor.login', absolute: false))
        ->assertValue('[data-input-otp]', '');
});
