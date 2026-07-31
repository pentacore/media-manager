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

    // The `assertSee('Overview')` anchor is load-bearing: assertDontSeeIn()
    // asserts count() === 0 on a locator, and Playwright's count() does not
    // throw when the root selector matches nothing. Without a positive
    // assertion every negative below would pass vacuously if
    // `[data-sidebar="content"]` ever disappeared.
    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Overview')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Admin')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Configuration')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Diagnostics')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Connections')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Users');
});

test('admins see both stats pages under distinct names', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    // `System stats` now lives inside the collapsed `Diagnostics` sub-group.
    // Landing on the page itself force-opens its parent, which exercises the
    // rename without toggling (and therefore without persisting) anything.
    visit('/admin/statistics')
        ->assertNoSmoke()
        ->assertSee('Watch stats')
        ->assertSee('System stats');
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
        ->assertSee('Configuration')
        ->assertSee('Diagnostics')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Connections')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Approval Rules')
        ->click('button:has-text("Configuration")')
        ->assertSee('Connections')
        ->assertSee('Users')
        ->assertSee('Approval Rules');
});

test('the parent group auto-opens when a child page is current', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/admin/users')
        ->assertNoSmoke()
        ->assertSee('Users')
        ->assertSee('Connections');
});

test('an opened sub-group stays open across a navigation', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->click('button:has-text("Diagnostics")')
        ->assertSee('Webhook Log')
        // The brief navigated via `Movies`, but every *arr-backed page
        // redirects straight back to the dashboard when no service connection
        // is configured, so nothing was ever navigated. `Activity log` needs no
        // connection. `Append-only audit feed` exists only after the visit
        // lands, and assertPathIs() reads the URL without retrying, so the
        // assertSee() in front of it is what makes this deterministic.
        ->click('[data-sidebar="content"] a:has-text("Activity log")')
        ->assertSee('Append-only audit feed')
        ->assertPathIs('/activity-log')
        ->assertSee('Webhook Log');
});
