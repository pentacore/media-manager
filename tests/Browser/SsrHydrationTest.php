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
        // Scoped to the palette's section wrapper: this assertion is what proves
        // hydration took, so it must fail when the Meta+k handler never ran.
        // Unscoped assertSee() is a case-insensitive substring match over the
        // whole page, and the server-rendered sidebar already contains
        // "Now Playing" — so it would pass with the palette still shut.
        ->assertSeeIn('[data-palette-sections]', 'Now Playing')
        ->type('input[type="search"]', 'severance')
        ->keys('input[type="search"]', 'Enter')
        ->assertPathIs('/media/search')
        ->assertQueryStringHas('q', 'severance');
})->skip(
    fn (): bool => Artisan::call('inertia:check-ssr') !== 0,
    'The Inertia SSR server is not running; run composer test:browser.',
);

/**
 * The browser server answers every request from the test's own container, and
 * Inertia memoises the SSR response in a scoped SsrState. If scoped instances
 * outlive a request, the second full page load replays the first one's HTML
 * and page props, so the page shows data from before the change.
 */
test('a second full page load server-renders the latest props', function (): void {
    $user = User::factory()->member()->create(['name' => 'Before Rename']);
    $this->actingAs($user);

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSeeIn('[data-test="sidebar-menu-button"]', 'Before Rename');

    $user->update(['name' => 'After Rename']);

    visit('/dashboard')
        ->assertAttribute('#app', 'data-server-rendered', 'true')
        ->assertNoSmoke()
        ->assertSeeIn('[data-test="sidebar-menu-button"]', 'After Rename');
})->skip(
    fn (): bool => Artisan::call('inertia:check-ssr') !== 0,
    'The Inertia SSR server is not running; run composer test:browser.',
);
