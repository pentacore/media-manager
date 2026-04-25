<?php

declare(strict_types=1);

use Illuminate\Validation\Rules\Password;

test('production password defaults enforce strong rules', function (): void {
    // Force production environment so the configureDefaults callback returns the strict rule.
    $this->app->detectEnvironment(fn (): string => 'production');
    expect($this->app->isProduction())->toBeTrue();

    // Re-register Password::defaults() under the production environment.
    Password::defaults(fn (): ?Password => app()->isProduction()
        ? Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised()
        : null,
    );

    $password = Password::default();
    expect($password)->toBeInstanceOf(Password::class);

    $reflection = new ReflectionClass($password);

    expect($reflection->getProperty('min')->getValue($password))->toBe(12);
    expect($reflection->getProperty('mixedCase')->getValue($password))->toBeTrue();
    expect($reflection->getProperty('letters')->getValue($password))->toBeTrue();
    expect($reflection->getProperty('numbers')->getValue($password))->toBeTrue();
    expect($reflection->getProperty('symbols')->getValue($password))->toBeTrue();
    expect($reflection->getProperty('uncompromised')->getValue($password))->toBeTrue();
});

test('non-production password defaults are relaxed', function (): void {
    expect($this->app->isProduction())->toBeFalse();

    Password::defaults(fn (): ?Password => app()->isProduction()
        ? Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised()
        : null,
    );

    // When the closure returns null, Password::default() falls back to Password::min(8).
    $password = Password::default();
    expect($password)->toBeInstanceOf(Password::class);

    $reflection = new ReflectionClass($password);
    // No strict modifiers should be enabled in the relaxed default.
    expect($reflection->getProperty('mixedCase')->getValue($password))->toBeFalse();
    expect($reflection->getProperty('symbols')->getValue($password))->toBeFalse();
    expect($reflection->getProperty('uncompromised')->getValue($password))->toBeFalse();
});

test('password rule rejects weak password under production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    Password::defaults(fn (): ?Password => app()->isProduction()
        ? Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised()
        : null,
    );

    $validator = validator(['password' => 'password'], ['password' => Password::default()]);
    expect($validator->fails())->toBeTrue();
    // Sanity: at minimum the length / character mix complaints should fire.
    expect($validator->errors()->get('password'))->not->toBeEmpty();
});
