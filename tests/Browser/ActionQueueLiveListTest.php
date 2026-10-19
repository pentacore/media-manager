<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\MediaReplacementStatus;
use App\Events\ActionRequestCreated;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\User;

/*
 * On an admin's Action Queue, members.actions carries four
 * ActionRequestCreated callbacks (layout toast, nav badge, the page's live
 * list, the page's tab-count reload) and three ActionRequestStatusChanged
 * callbacks (layout toast, nav badge, the page).
 */

function actionQueueLiveListenerCountScript(string $event): string
{
    return sprintf("(window.Pusher?.instances?.[0]?.channel('private-members.actions')?.callbacks?.get('%s') ?? []).length", $event);
}

/**
 * Polls inside the page until the condition holds (or ~5 s pass).
 */
function actionQueueLiveWaitScript(string $condition): string
{
    return confirmDialogWaitUntilScript($condition);
}

function actionQueueLiveFirstRowScript(): string
{
    return "document.querySelector('[data-action-row]')?.getAttribute('data-action-row')";
}

test('a request created live appears at the top of the unfiltered first page', function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed, 'title' => 'Example older request']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $created = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example live request']);
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestCreated', new ActionRequestCreated($created)->broadcastWith(), 4));
    $webpage->script(actionQueueLiveWaitScript(sprintf("%s === '%d'", actionQueueLiveFirstRowScript(), $created->id)));

    expect($webpage->script(actionQueueLiveFirstRowScript()))->toBe((string) $created->id);
    $webpage->assertSeeIn(sprintf('[data-action-row="%d"]', $created->id), 'Example live request')
        ->assertMissing('[data-action-stale]')
        ->assertNoSmoke();
});

test('a request created live in a filtered tab is announced, and Refresh brings it in', function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example waiting request']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', ['status' => 'pending'], absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $created = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example announced request']);
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestCreated', new ActionRequestCreated($created)->broadcastWith(), 4));
    $webpage->script(actionQueueLiveWaitScript("document.querySelector('[data-action-stale]') !== null"));

    $webpage->assertSeeIn('[data-action-stale]', '1 new')
        ->assertMissing(sprintf('[data-action-row="%d"]', $created->id))
        ->click('[data-action-stale] button');
    $webpage->script(actionQueueLiveWaitScript(sprintf("document.querySelector('[data-action-row=\"%d\"]') !== null", $created->id)));

    $webpage->assertSeeIn(sprintf('[data-action-row="%d"]', $created->id), 'Example announced request')
        ->assertMissing('[data-action-stale]')
        ->assertNoSmoke();
});

test('a changed replacement attempt reloads the listed request for an admin', function (): void {
    $mediaReplacementAttempt = MediaReplacementAttempt::factory()->create();
    $actionRequestId = $mediaReplacementAttempt->action_request_id;
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', ['request' => $actionRequestId], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-replacement-attempt-status]', 'requested');
    $webpage->script(appHydratedScript());

    $mediaReplacementAttempt->update(['status' => MediaReplacementStatus::Verified]);
    $webpage->script(echoEmitScript('admin.media-replacement', 'MediaReplacementAttemptChanged', ['action_request_id' => $actionRequestId], 1));
    $webpage->script(actionQueueLiveWaitScript("document.querySelector('[data-replacement-attempt-status]')?.innerText.includes('verified')"));

    $webpage->assertSeeIn('[data-replacement-attempt-status]', 'verified')
        ->assertNoSmoke();
});

test('the pagination opens the next page of requests', function (): void {
    ActionRequest::factory()->count(26)->create(['status' => ActionRequestStatus::Completed]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-action-row]', 25)
        ->click('[data-action-pagination] button:last-child');
    $webpage->script(actionQueueLiveWaitScript("document.querySelectorAll('[data-action-row]').length === 1"));

    $webpage->assertQueryStringHas('page', '2')
        ->assertCount('[data-action-row]', 1)
        ->assertNoSmoke();
});

test('a preselected request opens in the detail pane, and a click selects another', function (): void {
    $preselected = ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed, 'title' => 'Example preselected request']);
    $other = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example other request']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', ['request' => $preselected->id], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-title]', 'Example preselected request')
        ->click(sprintf('[data-action-row="%d"]', $other->id))
        ->assertSeeIn('[data-action-title]', 'Example other request')
        ->assertNoSmoke();
});

test('leaving the Action Queue and coming back binds its live listeners once', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example listed request']);
    $this->actingAs(User::factory()->admin()->create());
    $actionQueuePath = route('actions.requests.index', absolute: false);
    $dashboardPath = route('dashboard', absolute: false);

    $webpage = visit($actionQueuePath)->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueLiveWaitScript(actionQueueLiveListenerCountScript('ActionRequestCreated').' >= 4'));

    expect($webpage->script(actionQueueLiveListenerCountScript('ActionRequestCreated')))->toBe(4)
        ->and($webpage->script(actionQueueLiveListenerCountScript('ActionRequestStatusChanged')))->toBe(3);

    $webpage->click('[data-nav-item="Dashboard"]');
    $webpage->script(actionQueueLiveWaitScript(sprintf("window.location.pathname === '%s'", $dashboardPath)));
    $webpage->click('[data-nav-item="Action Queue"]');
    $webpage->script(actionQueueLiveWaitScript(sprintf("window.location.pathname === '%s' && document.querySelector('[data-action-row]') !== null", $actionQueuePath)));
    $webpage->script(actionQueueLiveWaitScript(actionQueueLiveListenerCountScript('ActionRequestCreated').' >= 4'));

    expect($webpage->script(actionQueueLiveListenerCountScript('ActionRequestCreated')))->toBe(4)
        ->and($webpage->script(actionQueueLiveListenerCountScript('ActionRequestStatusChanged')))->toBe(3);

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'completed',
        'result' => null,
        'updated_at' => $actionRequest->updated_at->addSeconds(60)->toISOString(),
        'status_version' => $actionRequest->status_version + 1,
    ], 3));
    $webpage->script(actionQueueLiveWaitScript(sprintf("document.body.innerText.includes('Request #%d is now completed')", $actionRequest->id)));

    $webpage->assertSeeIn(sprintf('[data-action-row="%d"]', $actionRequest->id), 'completed')
        ->assertNoSmoke();
});

/**
 * Waits for the layout's "New Action Request" toast, which the same
 * ActionRequestCreated broadcast raises after the page's list handled it.
 */
function actionQueueLiveCreatedToastScript(): string
{
    return actionQueueLiveWaitScript("document.body.innerText.includes('New Action Request')");
}

test('a created broadcast older than the listed row does not roll it back', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example settled request']);
    $stalePayload = new ActionRequestCreated($actionRequest)->broadcastWith();
    $actionRequest->update(['status' => ActionRequestStatus::Completed]);
    $row = sprintf('[data-action-row="%d"]', $actionRequest->id);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn($row, 'completed');
    $webpage->script(appHydratedScript());

    expect($stalePayload['status_version'])->toBeLessThan($actionRequest->fresh()->status_version);

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestCreated', $stalePayload, 4));
    $webpage->script(actionQueueLiveCreatedToastScript());

    $webpage->assertSeeIn($row, 'completed')
        ->assertDontSeeIn($row, 'pending')
        ->assertNoSmoke();
});

test('an unversioned created broadcast older than the listed row yields to it by its timestamp', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed, 'title' => 'Example timed request']);
    $stalePayload = [
        ...new ActionRequestCreated($actionRequest)->broadcastWith(),
        'status' => 'pending',
        'updated_at' => $actionRequest->updated_at->subSeconds(60)->toISOString(),
    ];
    unset($stalePayload['status_version']);
    $row = sprintf('[data-action-row="%d"]', $actionRequest->id);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn($row, 'completed');
    $webpage->script(appHydratedScript());
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestCreated', $stalePayload, 4));
    $webpage->script(actionQueueLiveCreatedToastScript());

    $webpage->assertSeeIn($row, 'completed')
        ->assertDontSeeIn($row, 'pending')
        ->assertNoSmoke();
});

test('a created broadcast as new as the listed row still replaces it', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example first title']);
    $row = sprintf('[data-action-row="%d"]', $actionRequest->id);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn($row, 'Example first title');
    $webpage->script(appHydratedScript());

    // A title change is no status change, so the version stays and the pair ties.
    $actionRequest->update(['title' => 'Example renamed title']);
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestCreated', new ActionRequestCreated($actionRequest->fresh())->broadcastWith(), 4));
    $webpage->script(actionQueueLiveWaitScript(sprintf("document.querySelector('%s')?.innerText.includes('Example renamed title') === true", $row)));

    $webpage->assertSeeIn($row, 'Example renamed title')
        ->assertNoSmoke();
});
