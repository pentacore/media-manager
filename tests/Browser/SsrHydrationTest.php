<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * Server rendering needs the Inertia SSR server listening; without it Inertia
 * silently falls back to client rendering and this is the only test that notices.
 * `composer test:browser` starts it, exactly as CI does, so a bare browser run
 * skips instead of reporting a failure that says nothing about the code.
 */
test('server-rendered dashboard hydrates and remains interactive', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit('/dashboard')
        ->assertAttribute('#app', 'data-server-rendered', 'true')
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        ->assertSee('Now Playing')
        ->type('input[type="search"]', 'severance')
        ->keys('input[type="search"]', 'Enter')
        ->assertPathIs('/media/search')
        ->assertQueryStringHas('q', 'severance');
})->skip(
    fn (): bool => Artisan::call('inertia:check-ssr') !== 0,
    'The Inertia SSR server is not running; run composer test:browser.',
);
