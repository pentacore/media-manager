<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\User;
use App\Notifications\ServiceWarning;
use Illuminate\Support\Str;

test('the sidebar footer shows the app version and the logo shows only the host', function (): void {
    config()->set('app.version', '1.7.2');
    $this->actingAs(User::factory()->member()->create());

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-app-version]', 'v1.7.2')
        ->assertScript("document.querySelector('[data-app-logo-subtitle]').textContent.trim() === window.location.hostname");
});

test('the header badge shows the unread notification count from the shared nav prop', function (): void {
    $member = User::factory()->member()->create();

    // There is no DatabaseNotification factory; these rows mirror what
    // ServiceWarning::toArray() stores.
    foreach (range(1, 3) as $number) {
        $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ServiceWarning::class,
            'data' => ['service' => 'sabnzbd', 'title' => sprintf('Disk warning %d', $number), 'message' => 'Less than 1 GB free', 'level' => 'disk_full'],
        ]);
    }

    $this->actingAs($member);

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-unread-notifications]', '3');
});

test('a partial reload leaves the sidebar badge alone until the next page visit', function (): void {
    ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->member()->create());

    // A fresh member's only non-zero badge is the Action Queue's, so
    // `[data-sidebar="menu-badge"]` resolves to exactly one element (see
    // SidebarNavigationTest).
    $webpage = visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-dashboard-pending-count]', '3 actions awaiting approval')
        ->assertSeeIn('[data-sidebar="menu-badge"]', '3');

    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    // Refresh is a partial reload of the dashboard's own props. The body count
    // reaching 4 proves the reload landed; the badge still reading 3 shows a
    // partial reload leaves it alone. It does not prove `nav` was skipped
    // server-side — the old eager version produced the same client-visible
    // result here too, since the client always drops shared props a partial
    // reload didn't ask for. That laziness is pinned by the query-count
    // assertions in tests/Feature/HandleInertiaRequestsTest.php instead.
    $webpage->click('[data-dashboard-refresh]')
        ->assertSeeIn('[data-dashboard-pending-count]', '4 actions awaiting approval')
        ->assertSeeIn('[data-sidebar="menu-badge"]', '3');

    // A full visit sends a fresh `nav` snapshot, and the badge follows it.
    $actionQueuePath = route('actions.requests.index', absolute: false);
    $webpage->click('[data-nav-item="Action Queue"]');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.location.pathname === '%s'", $actionQueuePath)));
    $webpage->assertPathIs($actionQueuePath)
        ->assertSeeIn('[data-sidebar="menu-badge"]', '4')
        ->assertNoSmoke();
});
