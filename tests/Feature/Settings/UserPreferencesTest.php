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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function whisparrPreferencesPayload(array $overrides = []): array
{
    return [
        'time_format' => '24h',
        'date_format' => 'iso',
        'timezone' => 'UTC',
        'first_day_of_week' => 1,
        'show_relative_time' => true,
        ...$overrides,
    ];
}

test('the Whisparr poster blur defaults to on, on the page and in the shared user', function (): void {
    $user = User::factory()->admin()->create(['preferences' => null]);

    $this->actingAs($user)
        ->get(route('settings.preferences.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('preferences.whisparr_blur_posters', true)
            ->where('auth.user.preferences.whisparr_blur_posters', true));
});

test('an admin can turn the Whisparr poster blur off', function (): void {
    $user = User::factory()->admin()->create(['preferences' => null]);

    $this->actingAs($user)
        ->put(route('settings.preferences.update'), whisparrPreferencesPayload(['whisparr_blur_posters' => false]))
        ->assertRedirect();

    expect($user->refresh()->resolvedPreferences()['whisparr_blur_posters'])->toBeFalse();
});

test('a save without the Whisparr toggle keeps the stored value', function (): void {
    $user = User::factory()->admin()->create(['preferences' => ['whisparr_blur_posters' => false]]);

    $this->actingAs($user)
        ->put(route('settings.preferences.update'), whisparrPreferencesPayload(['time_format' => '12h']))
        ->assertRedirect();

    expect($user->refresh()->resolvedPreferences())
        ->toMatchArray(['time_format' => '12h', 'whisparr_blur_posters' => false]);
});

test('a non-boolean Whisparr toggle is rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('settings.preferences.update'), whisparrPreferencesPayload(['whisparr_blur_posters' => 'maybe']))
        ->assertSessionHasErrors('whisparr_blur_posters');
});

test('a stored non-boolean blur value falls back to the default', function (): void {
    expect(UserPreferences::withDefaults(['whisparr_blur_posters' => 'no'])['whisparr_blur_posters'])->toBeTrue();
});
