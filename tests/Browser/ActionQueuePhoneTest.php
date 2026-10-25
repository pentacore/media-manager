<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([ExecuteActionRequest::class]);
});

test('action cards fit a phone and show the type, target, status, age and failure reason', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'type' => 'monitor_series', 'target_service' => 'radarr', 'title' => 'Monitor series "'.str_repeat('Severance', 8).'"', 'created_at' => now()->subMinutes(30)]);
    $failed = ActionRequest::factory()->described()->failed()->create();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertVisible(sprintf('[data-action-card="%d"]', $pending->id))
        ->assertSeeIn(sprintf('[data-action-card="%d"] [data-action-card-title]', $pending->id), 'SeveranceSeverance')
        ->assertSeeIn(sprintf('[data-action-card="%d"]', $pending->id), "act_{$pending->id} · monitor_series")
        // The target: only its service chip says radarr on this card.
        ->assertSeeIn(sprintf('[data-action-card="%d"]', $pending->id), 'radarr')
        ->assertSeeIn(sprintf('[data-action-card="%d"] time', $pending->id), '30m ago')
        ->assertSeeIn(sprintf('[data-action-card="%d"]', $pending->id), 'Pending')
        ->assertSeeIn(sprintf('[data-action-card-failure="%d"]', $failed->id), 'Execution failed')
        ->assertMissing(sprintf('[data-action-row="%d"]', $pending->id))
        ->assertCount('[data-action-card-select]', 0)
        ->assertScript(phoneOverflowScript('main'), '')
        ->assertNoSmoke();
});

test('an admin ticks pending action cards on a phone, and failed cards have no checkbox', function (): void {
    $first = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"']);
    $second = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $failed = ActionRequest::factory()->described()->failed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click(sprintf('[data-action-card-select="%d"]', $first->id))
        ->click(sprintf('[data-action-card-select="%d"]', $second->id))
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->assertCount(sprintf('[data-action-card-select="%d"]', $failed->id), 0)
        ->assertNoSmoke();
});

test('tapping an action card selects that action on a phone', function (): void {
    $older = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"', 'created_at' => now()->subHour()]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click(sprintf('[data-action-card-open="%d"]', $older->id));
    $webpage->script(confirmDialogWaitUntilScript('[...document.querySelectorAll("[data-action-title]")].some((title) => title.innerText.includes("Severance"))'));
    $webpage->assertScript('[...document.querySelectorAll("[data-action-title]")].some((title) => title.innerText.includes("Severance"))', true);
});

test('at desktop width the action queue keeps its table and detail pane', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible(sprintf('[data-action-row="%d"]', $pending->id))
        ->assertVisible(sprintf('[data-bulk-select="%d"]', $pending->id))
        ->assertMissing('[data-action-cards]')
        ->assertVisible('[data-action-approve]');
});

/**
 * Waits until the Action Queue sheet is open and its slide-in has finished.
 */
function actionQueuePhoneSheetOpenScript(): string
{
    return confirmDialogWaitUntilScript('!!document.querySelector("[data-action-sheet]") && document.querySelector("[data-action-sheet]").getAnimations().length === 0');
}

function actionQueuePhoneSheetClosedScript(): string
{
    return confirmDialogWaitUntilScript('!document.querySelector("[data-action-sheet]")');
}

/**
 * Waits for the layout's "Request #N is now <status>" toast, which the same
 * broadcast raises, so the page has handled the event.
 */
function actionQueuePhoneToastScript(int $actionRequestId, string $status): string
{
    return confirmDialogWaitUntilScript(sprintf("document.body.innerText.includes('Request #%d is now %s')", $actionRequestId, $status));
}

/**
 * Records, on every DOM change, the title the Action Queue sheet shows,
 * whether an Approve that is not inert sits under any other title, and
 * whether one is still clickable while the sheet slides out.
 */
function actionQueuePhoneSheetWatchScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmSheetTitles = [];
            window.__mmOtherApproveClickable = false;
            window.__mmClosingApproveClickable = false;
            const record = () => {
                const sheet = document.querySelector('[data-action-sheet]');
                if (!sheet) {
                    return;
                }
                const title = sheet.querySelector('[data-action-title]');
                const text = title ? title.textContent : '(no action)';
                if (window.__mmSheetTitles[window.__mmSheetTitles.length - 1] !== text) {
                    window.__mmSheetTitles.push(text);
                }
                const approve = sheet.querySelector('[data-action-approve]');
                if (approve && !approve.closest('[inert]') && !text.includes('Severance')) {
                    window.__mmOtherApproveClickable = true;
                }
                if (approve && !approve.closest('[inert]') && sheet.getAttribute('data-state') === 'closed') {
                    window.__mmClosingApproveClickable = true;
                }
            };
            record();
            new MutationObserver(record).observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true });
        })()
    JS;
}

/**
 * Two animation frames: long enough for a sheet opened in onMounted to have
 * rendered, without a timed sleep.
 */
function actionQueuePhoneTwoFramesScript(): string
{
    return '(async () => { await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))); })()';
}

test('a tapped action opens full screen with Approve and Decline pinned at the bottom, approves, and closes', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    // A short screen, so the detail is longer than the sheet.
    $webpage->resize(375, 480);
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $actionRequest->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());

    $webpage->assertSeeIn('[data-action-sheet] [data-action-title]', 'Severance')
        ->assertScript('Math.round(document.querySelector("[data-action-sheet]").getBoundingClientRect().width) === window.innerWidth', true)
        ->assertScript('(() => { const sheet = document.querySelector("[data-action-sheet]"); return sheet.scrollHeight > sheet.clientHeight; })()', true)
        // Unscrolled, both buttons already sit in the bottom bar.
        ->assertScript('(() => { const approve = document.querySelector("[data-action-sheet] [data-action-approve]").getBoundingClientRect(); const decline = document.querySelector("[data-action-sheet] [data-action-decline]").getBoundingClientRect(); return approve.bottom <= window.innerHeight && approve.top >= window.innerHeight - 120 && decline.bottom <= window.innerHeight; })()', true)
        ->click('[data-action-sheet] [data-action-approve]')
        ->assertSee('Action approved and queued.')
        ->assertMissing('[data-action-sheet] [data-action-approve]')
        ->assertVisible('[data-action-sheet]');

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);

    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueuePhoneSheetClosedScript());
    $webpage->assertMissing('[data-action-sheet]')
        ->assertVisible(sprintf('[data-action-card="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('closing the sheet returns to the same place in the list and focuses the card', function (): void {
    $requests = ActionRequest::factory()->count(14)->sequence(fn ($sequence): array => [
        'title' => sprintf('Monitor series "Example Show %02d"', $sequence->index),
        'created_at' => now()->subMinutes($sequence->index),
    ])->create(['status' => ActionRequestStatus::Pending]);
    $target = $requests[10];
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(sprintf('(() => { document.querySelector("[data-action-card-open=\"%d\"]").scrollIntoView({ block: "center" }); window.__mmListScroll = window.scrollY; })()', $target->id));
    $webpage->assertScript('window.__mmListScroll > 300', true);

    $webpage->click(sprintf('[data-action-card-open="%d"]', $target->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());
    $webpage->assertSeeIn('[data-action-sheet] [data-action-title]', 'Example Show 10');
    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueuePhoneSheetClosedScript());

    $webpage->assertScript('Math.abs(window.scrollY - window.__mmListScroll) <= 1', true)
        ->assertScript(sprintf('document.activeElement === document.querySelector("[data-action-card-open=\"%d\"]")', $target->id), true);
});

test('closing the sheet brings the card back into view after a new action was listed above it', function (): void {
    $requests = ActionRequest::factory()->count(14)->sequence(fn ($sequence): array => [
        'title' => sprintf('Monitor series "Example Show %02d"', $sequence->index),
        'created_at' => now()->subMinutes($sequence->index + 1),
    ])->create(['status' => ActionRequestStatus::Completed]);
    $target = $requests[10];
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    // The card sits at the bottom edge, so a card added above pushes it off
    // screen unless closing follows the card rather than the old scroll offset.
    $webpage->script(sprintf('(() => { const card = document.querySelector("[data-action-card-open=\"%d\"]"); card.scrollIntoView({ block: "end" }); })()', $target->id));
    $webpage->click(sprintf('[data-action-card-open="%d"]', $target->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());

    $created = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Example Live Show"']);
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestCreated', new ActionRequestCreated($created)->broadcastWith(), 4));
    $webpage->script(confirmDialogWaitUntilScript(sprintf('!!document.querySelector("[data-action-card=\"%d\"]")', $created->id)));
    $webpage->assertPresent(sprintf('[data-action-card="%d"]', $created->id));

    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueuePhoneSheetClosedScript());

    $webpage->assertScript(sprintf('(() => { const rect = document.querySelector("[data-action-card-open=\"%d\"]").getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= window.innerHeight + 1; })()', $target->id), true)
        ->assertScript(sprintf('document.activeElement === document.querySelector("[data-action-card-open=\"%d\"]")', $target->id), true)
        ->assertNoSmoke();
});

test('a notification deep link opens its action in the sheet on a phone', function (): void {
    $older = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"', 'created_at' => now()->subHour()]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', ['request' => $older->id], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueuePhoneSheetOpenScript());

    $webpage->assertSeeIn('[data-action-sheet] [data-action-title]', 'Severance')
        ->assertScript('document.getElementById(document.querySelector("[data-action-sheet]").getAttribute("aria-labelledby"))?.textContent.includes("Severance") ?? false', true);

    // Nothing had focus before a deep-linked sheet opened, so only the sheet's
    // own close handler can put focus on the action's card.
    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueuePhoneSheetClosedScript());
    $webpage->script(confirmDialogWaitUntilScript(sprintf('document.activeElement === document.querySelector("[data-action-card-open=\"%d\"]")', $older->id)));

    $webpage->assertScript(sprintf('document.activeElement === document.querySelector("[data-action-card-open=\"%d\"]")', $older->id), true)
        ->assertNoSmoke();
});

test('closing a deep-linked sheet drops the request from the URL without a new history entry', function (): void {
    $older = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"', 'created_at' => now()->subHour()]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', ['request' => $older->id], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueuePhoneSheetOpenScript());
    $webpage->script('(() => { window.__mmHistoryLength = history.length; })()');

    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueuePhoneSheetClosedScript());
    $webpage->script(confirmDialogWaitUntilScript('!new URL(window.location.href).searchParams.has("request")'));

    $webpage->assertScript('new URL(window.location.href).searchParams.has("request")', false)
        ->assertScript('history.length === window.__mmHistoryLength', true)
        ->assertScript('(history.state?.page?.props?.preselect ?? null) === null', true)
        ->assertNoSmoke();
});

test('a deep link to a request that is not listed opens no sheet', function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', ['request' => 999999], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueuePhoneTwoFramesScript());

    $webpage->assertMissing('[data-action-sheet]')
        ->assertNoSmoke();
});

test('at desktop width a deep link selects the action in the pane and opens no sheet', function (): void {
    $older = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"', 'created_at' => now()->subHour()]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', ['request' => $older->id], absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueuePhoneTwoFramesScript());

    $webpage->assertSeeIn('[data-action-title]', 'Severance')
        ->assertMissing('[data-action-sheet]');
});

test('a live update reaches the open sheet and an older one is ignored', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true, 'title' => 'Monitor series "Severance"']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $actionRequest->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());
    $webpage->assertVisible('[data-action-sheet] [data-action-approve]');

    // Newer: another admin approved it.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id, 'status' => 'approved', 'result' => null,
        'updated_at' => $actionRequest->updated_at->addSeconds(60)->toISOString(),
        'status_version' => $actionRequest->status_version + 1,
    ], 3));
    $webpage->script(actionQueuePhoneToastScript($actionRequest->id, 'approved'));
    $webpage->assertMissing('[data-action-sheet] [data-action-approve]')
        ->assertVisible('[data-action-sheet]');

    // Older: a late 'pending' with the row's own version must not undo it.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id, 'status' => 'pending', 'result' => null,
        'updated_at' => $actionRequest->updated_at->addSeconds(30)->toISOString(),
        'status_version' => $actionRequest->status_version,
    ], 3));
    $webpage->script(actionQueuePhoneToastScript($actionRequest->id, 'pending'));
    $webpage->assertMissing('[data-action-sheet] [data-action-approve]')
        ->assertNoSmoke();
});

test('approving under the Pending tab closes the sheet instead of showing the next action', function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true, 'title' => 'Monitor series "Andor"', 'created_at' => now()->subHour()]);
    $newer = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true, 'title' => 'Monitor series "Severance"']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', ['status' => 'pending'], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $newer->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());
    // Watch the sheet through its slide-out: every title it shows, and whether
    // an Approve for any other action is ever clickable in it.
    $webpage->script(actionQueuePhoneSheetWatchScript());

    $webpage->click('[data-action-sheet] [data-action-approve]')
        ->assertSee('Action approved and queued.');
    $webpage->script(actionQueuePhoneSheetClosedScript());

    $webpage->assertMissing('[data-action-sheet]')
        ->assertMissing(sprintf('[data-action-card="%d"]', $newer->id))
        ->assertScript('window.__mmSheetTitles.length > 0 && window.__mmSheetTitles.every((title) => title.includes("Severance"))', true)
        ->assertScript('window.__mmOtherApproveClickable', false)
        // The sliding-out sheet still shows the approved action: it is inert.
        ->assertScript('window.__mmClosingApproveClickable', false);

    // The action's card is gone, so focus lands on the page heading.
    $webpage->script(confirmDialogWaitUntilScript('document.activeElement?.tagName === "H1"'));
    $webpage->assertScript('document.activeElement?.tagName', 'H1')
        ->assertNoSmoke();
    expect($newer->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('an uncertain failure retries from the sheet only after the confirm', function (): void {
    $actionRequest = ActionRequest::factory()->described()->needsReconciliation()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $actionRequest->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());

    $webpage->assertVisible('[data-action-sheet] [data-action-failure]')
        ->click('[data-action-sheet] [data-action-retry]')
        ->assertVisible('[data-confirm-dialog]')
        ->click('[data-confirm-cancel]');
    $webpage->script(confirmDialogGoneScript());
    $webpage->assertVisible('[data-action-sheet]');

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Failed);

    $webpage->click('[data-action-sheet] [data-action-retry]')
        ->click('[data-confirm-accept]')
        ->assertSee('Action requeued for execution.')
        ->assertNoSmoke();
    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('the sheet shows the second opinion and a rejection reason', function (): void {
    $held = ActionRequest::factory()->secondOpinion(agrees: false, reason: 'Wrong series.')->create([
        'origin' => 'agent', 'status' => ActionRequestStatus::Pending, 'requires_approval' => true,
    ]);
    $rejected = ActionRequest::factory()->create(['status' => ActionRequestStatus::Rejected, 'result' => ['rejection_reason' => 'Not wanted on this server.']]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $held->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());
    $webpage->assertSeeIn('[data-action-sheet] [data-action-second-opinion]', 'Wrong series.');
    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueuePhoneSheetClosedScript());

    $webpage->click(sprintf('[data-action-card-open="%d"]', $rejected->id));
    $webpage->script(actionQueuePhoneSheetOpenScript());
    $webpage->assertSeeIn('[data-action-sheet] [data-action-rejection-reason]', 'Not wanted on this server.')
        ->assertNoSmoke();
});
