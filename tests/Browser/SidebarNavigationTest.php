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

    // Scoped to the badge element itself. A bare assertSee('3') was vacuous:
    // the same pending count feeds "3 actions awaiting approval", "3 pending ·
    // 0 failed" and a Pill in the dashboard body, so it stayed green with
    // SidebarMenuBadge deleted from NavMain outright. This pair is the only
    // badge coverage in the repo (RealtimeFlowsTest is skipped), so it is also
    // the only guard on useNavCounts' seeding.
    //
    // `[data-sidebar="menu-badge"]` resolves to exactly one element in this
    // state, which assertSeeIn() requires — it chains getByText() onto a strict
    // locator, and a second match would raise a Playwright strict-mode
    // violation that waitForExpectation() does not catch. Verified with
    // assertCount(): Action Queue is the only counter above zero for a fresh
    // member, and a member sees no collapsible group (SidebarMenuSub carries
    // the same data-sidebar value upstream, so admin sub-groups would add
    // matches).
    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'Action Queue')
        ->assertSeeIn('[data-sidebar="menu-badge"]', '3');
});

test('the Action Queue badge is absent when nothing is pending', function (): void {
    // Pins the other half of the badge contract: `3` is not incidental page
    // text, so the assertion above is genuinely reading the counter.
    $this->actingAs(User::factory()->member()->create());

    // assertCount() rather than assertDontSeeIn(): with nothing pending the
    // sidebar renders no badge at all, so `[data-sidebar="menu-badge"]` matches
    // zero elements (verified) and assertDontSeeIn() — a count() === 0 check on
    // a locator chained off that root — would pass no matter how broken the
    // navigation was. Asserting the badge count directly says what is meant and
    // cannot go vacuous. The scoped `Action Queue` anchor keeps the test honest
    // if the sidebar ever fails to render at all.
    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'Action Queue')
        ->assertCount('[data-sidebar="menu-badge"]', 0);
});

// The negative assertions below are scoped to `[data-sidebar="content"]` (the
// sidebar's nav-group container). Pest's unscoped assertDontSee() matches
// case-insensitive substrings anywhere on the page, and the dashboard body
// legitimately renders "Live activity" and "active connections" while the
// header renders "Search media, requests, actions…" — none of which are
// sidebar entries. Scoping keeps the assertions about the navigation only.
test('the sidebar shows four single-concept groups with the renamed labels', function (): void {
    $this->actingAs(User::factory()->member()->create());

    // Every positive is scoped, and the three group labels are scoped to
    // `[data-sidebar="group-label"]` (the attribute SidebarGroupLabel carries)
    // rather than the whole nav container, so they pin *group labels* and not
    // merely "this string appears in the sidebar".
    //
    // Unscoped, three of the six were satisfiable without the sidebar at all:
    // `Overview` by Dashboard.vue's breadcrumb, `Activity` by the body's "Live
    // activity", `Media` by the header's "Search media, requests, actions…".
    // `Activity` cannot be scoped to `[data-sidebar="content"]` — that matches
    // both the group label and the `Activity log` item, two elements, which is a
    // strict-mode violation inside assertSeeIn() rather than a clean failure.
    // Scoped to group labels it resolves to exactly one element (verified with
    // assertCount(), as were all the pairs below).
    //
    // The label count is what actually pins the headline claim: a member sees
    // the three non-admin groups, so regrouping back to two would fail here
    // whatever the groups were renamed to.
    visit('/dashboard')
        ->assertNoSmoke()
        ->assertCount('[data-sidebar="group-label"]', 3)
        ->assertSeeIn('[data-sidebar="group-label"]', 'Overview')
        ->assertSeeIn('[data-sidebar="group-label"]', 'Media')
        ->assertSeeIn('[data-sidebar="group-label"]', 'Activity')
        ->assertSeeIn('[data-sidebar="content"]', 'Watch stats')
        ->assertSeeIn('[data-sidebar="content"]', 'Grab queue')
        ->assertSeeIn('[data-sidebar="content"]', 'TV Series')
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

    // The mobile positive is scoped: unscoped, `Search` is satisfied by
    // AppSidebarHeader's "Search media, requests, actions…" placeholder whether
    // the mobile-only nav entry rendered or not.
    //
    // The link counts pin the whole visible taxonomy in one number — 13 leaf
    // links at desktop (3 Overview + 4 Media + 6 Activity) and 14 at mobile
    // where `Search` joins them. Admin sub-group children are not counted at
    // either width: their parents are CollapsibleTrigger buttons and the closed
    // CollapsibleContent is out of the DOM, so an admin also sees 13.
    visit('/dashboard')
        ->assertNoSmoke()
        ->assertDontSeeIn('[data-sidebar="content"]', 'Search')
        ->assertCount('[data-sidebar="content"] a', 13)
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertSeeIn('[data-sidebar="content"]', 'Search')
        ->assertCount('[data-sidebar="content"] a', 14);
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
        // No explicit wait is needed after the click even though click() returns
        // before the Inertia visit lands. visit() hands back an
        // AwaitableWebpage, whose __call wraps every method in
        // Execution::waitForExpectation() — a retry loop budgeted by
        // Playwright::timeout(), which tests/Pest.php sets to 30_000ms. So the
        // assertions below poll rather than sampling once, and
        // `Append-only audit feed` exists only on the destination page.
        ->click('[data-sidebar="content"] a:has-text("Activity log")')
        ->assertSee('Append-only audit feed')
        ->assertPathIs('/activity-log')
        // Scoped: the activity-log page renders webhook-derived rows, so an
        // unscoped assertion here would not be reading the sidebar.
        ->assertSeeIn('[data-sidebar="content"]', 'Webhook Log');
});
