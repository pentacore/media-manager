<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\User;

/**
 * The newest status version, which the server's pending count reflects.
 */
function navBadgeOrderNewestVersion(): int
{
    return (int) ActionRequest::query()->max('status_version');
}

/**
 * Waits inside the page until the sidebar's Action Queue badge reads $count.
 */
function navBadgeOrderWaitForScript(string $count): string
{
    return confirmDialogWaitUntilScript(sprintf("document.querySelector('[data-nav-badge=\"Action Queue\"]')?.innerText.trim() === '%s'", $count));
}

/**
 * Rests inside the page so a late handler or watcher would have run.
 */
function navBadgeOrderRestScript(): string
{
    return '(async () => { await new Promise((resolve) => setTimeout(resolve, 500)); })()';
}

/**
 * An ActionRequestStatusChanged payload that approves $actionRequest and
 * carries the pending count a worker read at $version.
 *
 * @return array<string, mixed>
 */
function navBadgeOrderApproved(ActionRequest $actionRequest, int $version, int $pendingCount): array
{
    return [
        'id' => $actionRequest->id,
        'status' => 'approved',
        'result' => null,
        'updated_at' => $actionRequest->updated_at->addSeconds(5)->toISOString(),
        'status_version' => $version,
        'pending_count' => $pendingCount,
        'pending_version' => $version,
    ];
}

test('a pending count that lands after a newer one is ignored', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $newest = navBadgeOrderNewestVersion();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3');
    $webpage->script(appHydratedScript());

    // Two queue workers counted 2 and then 1, but published 1 first.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', navBadgeOrderApproved($pending[1], $newest + 2, 1)));
    $webpage->script(navBadgeOrderWaitForScript('1'));
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', navBadgeOrderApproved($pending[0], $newest + 1, 2)));
    $webpage->script(navBadgeOrderRestScript());

    $webpage->assertSeeIn('[data-nav-badge="Action Queue"]', '1')
        ->assertNoSmoke();
});

test('going back restores the earlier page but not its older pending count', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $newest = navBadgeOrderNewestVersion();
    $this->actingAs(User::factory()->admin()->create());
    $dashboardPath = route('dashboard', absolute: false);
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit($dashboardPath)
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3');
    $webpage->script(appHydratedScript());
    $webpage->click('[data-nav-item="Action Queue"]');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.location.pathname === '%s'", $actionQueuePath)));

    // Settled elsewhere while the Action Queue is open.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', navBadgeOrderApproved($pending[0], $newest + 1, 2)));
    $webpage->script(navBadgeOrderWaitForScript('2'));

    // Back restores the Dashboard's saved props, its `nav` (3) included, without a request.
    $webpage->script('window.history.back()');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.location.pathname === '%s'", $dashboardPath)));
    $webpage->script(navBadgeOrderRestScript());

    $webpage->assertPathIs($dashboardPath)
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '2')
        ->assertNoSmoke();
});

/**
 * Holds back the answer to the page's next full Inertia visit (no partial
 * reload header), as a slow network would: it is sent and answered at once,
 * but the page only sees the answer when window.__mmReleaseHeld() runs.
 */
function navBadgeOrderHoldFullVisitScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmHeldAnswered = false;
            window.__mmReleaseHeld = () => {};
            let armed = true;
            const setRequestHeader = XMLHttpRequest.prototype.setRequestHeader;
            XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                const header = String(name).toLowerCase();
                if (header === 'x-inertia') {
                    this.__mmInertia = true;
                }
                if (header === 'x-inertia-partial-data' || header === 'purpose') {
                    this.__mmNotFull = true;
                }
                return setRequestHeader.call(this, name, value);
            };
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (body) {
                if (armed && this.__mmInertia && !this.__mmNotFull) {
                    armed = false;
                    const onload = this.onload;
                    this.onload = (...args) => {
                        window.__mmReleaseHeld = () => onload.apply(this, args);
                        window.__mmHeldAnswered = true;
                    };
                }
                return send.call(this, body);
            };
        })()
    JS;
}

test('a full visit sent after a too-high pending version arrived brings the badge back to the server count', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $newest = navBadgeOrderNewestVersion();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3');
    $webpage->script(appHydratedScript());

    // A version the database cannot reach (a restored database, a bogus
    // payload): every later real count would look older than it.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', navBadgeOrderApproved($pending[0], $newest + 1000, 7)));
    $webpage->script(navBadgeOrderWaitForScript('7'));

    // A full visit (the Pending tab), sent after that version arrived.
    $webpage->click('[data-test="tab-pending"]');
    $webpage->script(navBadgeOrderWaitForScript('3'));

    $webpage->assertSeeIn('[data-nav-badge="Action Queue"]', '3')
        ->assertNoSmoke();
});

test('a full visit sent before a newer pending count arrived still yields to it', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $newest = navBadgeOrderNewestVersion();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3');
    $webpage->script(appHydratedScript());
    $webpage->script(navBadgeOrderHoldFullVisitScript());

    // The visit reads the count (3) first; its answer lands after a newer
    // broadcast (2) arrived.
    $webpage->click('[data-test="tab-pending"]');
    $webpage->script(confirmDialogWaitUntilScript('window.__mmHeldAnswered === true'));
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', navBadgeOrderApproved($pending[0], $newest + 1, 2)));
    $webpage->script(navBadgeOrderWaitForScript('2'));
    $webpage->script('window.__mmReleaseHeld()');
    $webpage->script(confirmDialogWaitUntilScript("new URLSearchParams(window.location.search).get('status') === 'pending'"));
    $webpage->script(navBadgeOrderRestScript());

    $webpage->assertSeeIn('[data-nav-badge="Action Queue"]', '2')
        ->assertNoSmoke();
});
