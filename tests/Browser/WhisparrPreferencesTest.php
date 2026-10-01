<?php

declare(strict_types=1);

use App\Models\User;

test('an admin turns the Whisparr poster blur off from the preferences page', function (): void {
    $admin = User::factory()->admin()->create(['preferences' => null]);
    $this->actingAs($admin);

    visit(route('settings.preferences.edit', absolute: false))
        ->assertNoSmoke()
        ->assertSee('Blur posters on the Whisparr pages')
        ->click('[data-whisparr-blur-toggle]')
        ->click('[data-test="update-preferences-button"]')
        ->assertSee('Preferences saved.')
        ->assertNoSmoke();

    expect($admin->refresh()->resolvedPreferences()['whisparr_blur_posters'])->toBeFalse();
});

test('a member does not see the Whisparr poster toggle', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('settings.preferences.edit', absolute: false))
        ->assertNoSmoke()
        ->assertSee('Relative time')
        ->assertCount('[data-whisparr-blur-toggle]', 0);
});
