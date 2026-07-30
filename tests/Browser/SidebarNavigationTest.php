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

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertDontSeeIn('[data-sidebar="content"]', 'Admin')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Connections')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Users');
});

test('admins see both stats pages under distinct names', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
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
