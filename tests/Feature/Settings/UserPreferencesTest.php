<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\UserPreferences;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('GET /settings/preferences returns defaults for a fresh user', function (): void {
    $user = User::factory()->create(['preferences' => null]);

    $this->actingAs($user)
        ->get(route('settings.preferences.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Preferences')
            ->where('preferences.time_format', '24h')
            ->where('preferences.date_format', 'iso')
            ->where('preferences.timezone', 'UTC')
            ->where('preferences.first_day_of_week', 1)
            ->where('preferences.show_relative_time', true)
            ->has('timezones')
            ->has('options.time_formats')
            ->has('options.date_formats')
            ->has('options.week_starts')
        );
});

test('PUT /settings/preferences persists a valid payload', function (): void {
    $user = User::factory()->create(['preferences' => null]);

    $this->actingAs($user)
        ->put(route('settings.preferences.update'), [
            'time_format' => '12h',
            'date_format' => 'us',
            'timezone' => 'Europe/Stockholm',
            'first_day_of_week' => 0,
            'show_relative_time' => false,
        ])
        ->assertRedirect();

    expect($user->refresh()->resolvedPreferences())->toMatchArray([
        'time_format' => '12h',
        'date_format' => 'us',
        'timezone' => 'Europe/Stockholm',
        'first_day_of_week' => 0,
        'show_relative_time' => false,
    ]);
});

test('PUT /settings/preferences rejects invalid time_format', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.preferences.update'), [
            'time_format' => 'noon',
            'date_format' => 'iso',
            'timezone' => 'UTC',
            'first_day_of_week' => 1,
            'show_relative_time' => true,
        ])
        ->assertSessionHasErrors('time_format');
});

test('PUT /settings/preferences rejects unknown timezone', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.preferences.update'), [
            'time_format' => '24h',
            'date_format' => 'iso',
            'timezone' => 'Mars/Olympus_Mons',
            'first_day_of_week' => 1,
            'show_relative_time' => true,
        ])
        ->assertSessionHasErrors('timezone');
});

test('UserPreferences::withDefaults sanitizes garbage values', function (): void {
    $sanitized = UserPreferences::withDefaults([
        'time_format' => 'noon',
        'date_format' => 'whatever',
        'timezone' => 'Mars/Olympus_Mons',
        'first_day_of_week' => 99,
        'show_relative_time' => 'yes',
    ]);

    expect($sanitized)->toMatchArray(UserPreferences::defaults());
});

test('SharedUserResource exposes resolved preferences', function (): void {
    $user = User::factory()->create(['preferences' => null]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.preferences.time_format', '24h')
            ->where('auth.user.preferences.date_format', 'iso')
            ->where('auth.user.preferences.timezone', 'UTC')
            ->where('auth.user.preferences.show_relative_time', true)
        );
});
