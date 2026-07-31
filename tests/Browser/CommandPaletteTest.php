<?php

declare(strict_types=1);

use App\Models\User;

// Every palette assertion below that reads a navigation label is scoped to
// `[data-palette-sections]`, the wrapper around the palette's section headings
// and link rows. Unscoped assertSee()/assertDontSee() are case-insensitive
// substring matches over the whole page (Playwright `getByText`), and the
// sidebar renders the very same labels as the palette — so an unscoped
// assertSee('TV Series') after opening the palette is satisfied by the sidebar
// whether or not the palette contains that entry. The dialog root would not be
// tight enough either: its own description ("Search your library or jump to a
// page.") and the search input both contain words we need to assert against.

test('Cmd+K opens the command palette and Enter searches', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member);

    visit('/dashboard')
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        // Scoped: the sidebar also renders "Now Playing", so an unscoped
        // assertion here would pass with the palette still shut.
        ->assertSeeIn('[data-palette-sections]', 'Now Playing')
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
    // The copy exists nowhere else in the app, so it needs no scoping.
    visit('/dashboard')
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        ->type('input[type="search"]', 'qqqqqqqq')
        ->assertSee('No matching pages');
});

test('clicking a palette quick link navigates to that page', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member);

    // Scope to the palette button (sidebar has the same text, so an
    // unscoped click on "Activity log" would be ambiguous).
    visit('/dashboard')
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        ->click('[data-palette-link]:has-text("Activity log")')
        ->assertPathIs('/activity-log');
});

test('palette labels match the sidebar labels', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        ->assertSeeIn('[data-palette-sections]', 'TV Series')
        ->assertSeeIn('[data-palette-sections]', 'Action Queue')
        ->assertSeeIn('[data-palette-sections]', 'Watch stats')
        // Destinations the hand-written list omitted entirely.
        ->assertSeeIn('[data-palette-sections]', 'Seasonal Anime')
        ->assertSeeIn('[data-palette-sections]', 'Downloads')
        ->assertSeeIn('[data-palette-sections]', 'Grab queue')
        // The labels the drifted copy used for those same three pages.
        ->assertDontSeeIn('[data-palette-sections]', 'Action Requests')
        ->assertDontSeeIn('[data-palette-sections]', 'Statistics');
});

test('the palette exposes admin pages to admins under a section heading', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        ->assertSeeIn('[data-palette-sections]', 'Admin · Configuration')
        ->assertSeeIn('[data-palette-sections]', 'Admin · Diagnostics')
        ->click('[data-palette-link]:has-text("Users")')
        ->assertPathIs('/admin/users');
});

test('the palette hides admin pages from members', function (): void {
    $this->actingAs(User::factory()->member()->create());

    // The `Overview` anchor is load-bearing: assertDontSeeIn() asserts
    // count() === 0 on a locator, and Playwright's count() does not throw when
    // the root selector matches nothing. Without a positive assertion reading
    // the same container, every negative below would pass vacuously if the
    // palette simply never opened.
    visit('/dashboard')
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        ->assertSeeIn('[data-palette-sections]', 'Overview')
        ->assertDontSeeIn('[data-palette-sections]', 'Admin')
        ->assertDontSeeIn('[data-palette-sections]', 'Connections')
        ->assertDontSeeIn('[data-palette-sections]', 'Approval Rules')
        ->assertDontSeeIn('[data-palette-sections]', 'Webhook Log');
});

test('the palette never lists the mobile-only Search link', function (): void {
    $this->actingAs(User::factory()->member()->create());

    // `Search` is a leaf of the `Media` group, so a leaked mobileOnly item
    // would render inside `[data-palette-sections]` as the bare word "Search"
    // under the existing `Media` heading — never as "Media · Search", which is
    // the shape reserved for children of a parent row. Asserting the latter
    // would be unfalsifiable. Scoping is what makes the bare word usable: the
    // dialog description and the input placeholder both contain "Search", but
    // the section container holds only headings and link titles, none of which
    // contain that substring. `Media` anchors the negative to the same
    // container. The mobile viewport is the case that matters: the sidebar does
    // render this item at 390px, and the palette still must not.
    visit('/dashboard')
        ->resize(390, 844)
        ->assertNoSmoke()
        ->keys(':root', 'Meta+k')
        ->assertSeeIn('[data-palette-sections]', 'Media')
        ->assertDontSeeIn('[data-palette-sections]', 'Search');
});
