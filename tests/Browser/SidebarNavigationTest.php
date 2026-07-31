<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\User;

test('the Action Queue badge renders the pending action count', function (): void {
    $member = User::factory()->member()->create();

    ActionRequest::factory()->count(3)->create([
        'status' => ActionRequestStatus::Pending,
    ]);

    $this->actingAs($member);

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Action Queue')
        ->assertSee('3');
});

test('the Action Queue badge is absent when nothing is pending', function (): void {
    // Pins the other half of the badge contract: `3` is not incidental page
    // text, so the assertion above is genuinely reading the counter.
    $this->actingAs(User::factory()->member()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Action Queue')
        ->assertDontSee('3');
});

// The negative assertions below are scoped to `[data-sidebar="content"]` (the
// sidebar's nav-group container). Pest's unscoped assertDontSee() matches
// case-insensitive substrings anywhere on the page, and the dashboard body
// legitimately renders "Live activity" and "active connections" while the
// header renders "Search media, requests, actions…" — none of which are
// sidebar entries. Scoping keeps the assertions about the navigation only.
test('the sidebar shows four single-concept groups with the renamed labels', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Overview')
        ->assertSee('Media')
        ->assertSee('Activity')
        ->assertSee('Watch stats')
        ->assertSee('Grab queue')
        ->assertSee('TV Series')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Statistics')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Library activity')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Live');
});

test('members see no admin group', function (): void {
    $this->actingAs(User::factory()->member()->create());

    // The `Overview` anchor is load-bearing: assertDontSeeIn() asserts
    // count() === 0 on a locator, and Playwright's count() does not throw when
    // the root selector matches nothing. Without a positive assertion every
    // negative below would pass vacuously if `[data-sidebar="content"]` ever
    // disappeared. It has to be assertSeeIn() against that same selector — a
    // bare assertSee('Overview') is satisfied by the layout breadcrumb
    // (Dashboard.vue's `{ title: 'Overview' }`) and so anchors nothing.
    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'Overview')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Admin')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Configuration')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Diagnostics')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Connections')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Users');
});

test('admins see both stats pages under distinct names', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    // `System stats` now lives inside the collapsed `Diagnostics` sub-group, so
    // it is no longer visible on the dashboard. Landing on the page itself
    // force-opens its parent, which covers the rename and the auto-open in one
    // visit and needs no click. Both assertions are scoped: the admin
    // statistics page renders its own headings, so unscoped ones would not be
    // reading the navigation.
    visit('/admin/statistics')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'Watch stats')
        ->assertSeeIn('[data-sidebar="content"]', 'System stats');
});

test('the Search link is hidden on desktop and shown on mobile', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertDontSeeIn('[data-sidebar="content"]', 'Search')
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertSee('Search');
});

test('admin sub-groups start collapsed and reveal children on click', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'Configuration')
        ->assertSeeIn('[data-sidebar="content"]', 'Diagnostics')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Connections')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Approval Rules')
        ->click('[data-sidebar="content"] button:has-text("Configuration")')
        // Scoped: the dashboard body renders "0 connections" and "No service
        // connections configured.", either of which would satisfy an unscoped
        // assertSee('Connections') whether the group expanded or not.
        ->assertSeeIn('[data-sidebar="content"]', 'Connections')
        ->assertSeeIn('[data-sidebar="content"]', 'Users')
        ->assertSeeIn('[data-sidebar="content"]', 'Approval Rules');
});

test('the parent group auto-opens when a child page is current', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    // Scoped: the users admin page renders its own "Users" heading, so an
    // unscoped assertion would pass with the parent group still shut.
    visit('/admin/users')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'Users')
        ->assertSeeIn('[data-sidebar="content"]', 'Connections');
});

test('an opened sub-group stays open across a navigation', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->click('[data-sidebar="content"] button:has-text("Diagnostics")')
        ->assertSeeIn('[data-sidebar="content"]', 'Webhook Log')
        // The brief navigated via `Movies`, but every *arr-backed page redirects
        // straight back to the dashboard when no service connection is
        // configured, so nothing was ever navigated. `Activity log` needs no
        // connection.
        //
        // The hover() is the synchronisation point, and it is load-bearing.
        // Nothing else in this plugin's fluent API polls: assertSee() is
        // getByText()->all() plus an isVisible() loop over whatever exists at
        // that instant, assertPathIs() reads page->url() once, and
        // waitForEvent('networkidle') is a no-op for an Inertia visit — it
        // forwards to Playwright's waitForLoadState(), and `networkidle` is
        // never un-fired for a same-document pushState navigation (measured:
        // returns in 0.0ms with the URL still /dashboard). hover() routes to
        // Locator::hover(), which does use Playwright's actionability waiter,
        // and `h1:has-text("Activity log")` exists only on the destination page.
        // Measured: click() returns with the URL still /dashboard, then hover()
        // blocks ~135ms and the URL is /activity-log once it returns.
        ->click('[data-sidebar="content"] a:has-text("Activity log")')
        ->hover('h1:has-text("Activity log")')
        ->assertPathIs('/activity-log')
        ->assertSee('Append-only audit feed')
        // Scoped: the activity-log page renders webhook-derived rows, so an
        // unscoped assertion here would not be reading the sidebar.
        ->assertSeeIn('[data-sidebar="content"]', 'Webhook Log');
});
