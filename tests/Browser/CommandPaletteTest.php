<?php

declare(strict_types=1);

use App\Models\User;

test('Cmd+K opens the command palette and Enter searches', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->keys(':root', 'Meta+k')
        ->assertSee('Now Playing')
        ->type('input[type="search"]', 'severance')
        ->keys('input[type="search"]', 'Enter')
        ->assertPathIs('/media/search')
        ->assertQueryStringHas('q', 'severance');
});

test('command palette filters quick links by query', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member);

    // A bogus query has no matching quick links — the empty-state copy
    // proves the palette's own filtering ran (sidebar nav has the same
    // link text as the palette so we can't use sidebar-vs-palette diffs).
    visit('/dashboard')
        ->keys(':root', 'Meta+k')
        ->type('input[type="search"]', 'qqqqqqqq')
        ->assertSee('No matching pages');
});

test('clicking a palette quick link navigates to that page', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member);

    // Scope to the palette button (sidebar has the same text, so an
    // unscoped click on "Activity Log" would be ambiguous).
    visit('/dashboard')
        ->keys(':root', 'Meta+k')
        ->click('[data-palette-link]:has-text("Activity Log")')
        ->assertPathIs('/activity-log');
});
