<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Arr\ArrWriteUnconfirmed;
use Illuminate\Support\Facades\Queue;

/*
 * Every live emit waits for the three ActionRequestStatusChanged listeners an
 * admin's Action Queue binds on members.actions: the layout's toast
 * (useNotifications), the sidebar badge (useNavCounts) and the page itself.
 */

/**
 * Waits for the layout's "Request #N is now <status>" toast, which the same
 * broadcast raises, so the page has handled the event before assertions run.
 */
function actionQueueFailureWaitForToastScript(int $actionRequestId, string $status): string
{
    return confirmDialogWaitUntilScript(sprintf("document.body.innerText.includes('Request #%d is now %s')", $actionRequestId, $status));
}

test('an admin sees why an action failed, and a plain failure retries in one click', function (): void {
    $actionRequest = ActionRequest::factory()->described()->failed()->create();
    // Faked after the factories: a later Queue::fake replaces the Browser
    // beforeEach fake, and the WebhookEvent factory creates a connection.
    Queue::fake([ExecuteActionRequest::class]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id), 'Execution failed')
        ->assertSeeIn('[data-action-failure-reason]', 'Execution failed')
        ->assertSeeIn('[data-action-failure-message]', 'Sonarr answered 404 Not Found.')
        ->assertMissing('[data-action-failure-uncertain]')
        ->click('[data-action-retry]')
        ->assertSee('Action requeued for execution.')
        ->assertMissing('[data-confirm-dialog]')
        ->assertMissing('[data-action-failure]')
        ->assertNoSmoke();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);
    Queue::assertPushed(ExecuteActionRequest::class);
});

test('retrying an action with an unknown outcome asks first and repeats the reason', function (): void {
    $actionRequest = ActionRequest::factory()->described()->needsReconciliation()->create();
    Queue::fake([ExecuteActionRequest::class]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id), 'Needs reconciliation')
        ->assertSeeIn('[data-action-failure-uncertain]', 'Outcome unknown — check Sonarr before retrying.')
        ->assertSeeIn('[data-action-failure-message]', 'whether it was applied is unknown')
        ->click('[data-action-retry]')
        ->assertSeeIn('[data-confirm-dialog]', 'Retry an action with an unknown outcome?')
        ->assertSeeIn('[data-confirm-dialog]', 'Needs reconciliation. Outcome unknown — check Sonarr before retrying.')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Failed);
    Queue::assertNotPushed(ExecuteActionRequest::class);

    $webpage->click('[data-action-retry]')
        ->click('[data-confirm-accept]')
        ->assertSee('Action requeued for execution.')
        ->assertNoSmoke();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
});

test('a request that never started is not treated as an unknown outcome', function (): void {
    $actionRequest = ActionRequest::factory()->described()->failed()->create(['result' => [
        'success' => false,
        'reason' => 'needs_reconciliation',
        'message' => 'This request was approved more than 24 hours ago and its execution job never started. Review it before re-approving by hand.',
        'indeterminate' => false,
        'never_started' => true,
    ]]);
    Queue::fake([ExecuteActionRequest::class]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-failure-reason]', 'Needs reconciliation')
        ->assertMissing('[data-action-failure-uncertain]')
        ->click('[data-action-retry]')
        ->assertSee('Action requeued for execution.')
        ->assertMissing('[data-confirm-dialog]');

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('a failed request with no recorded result still shows a failure', function (): void {
    $actionRequest = ActionRequest::factory()->described()->failed()->create(['result' => null]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id), 'Failed')
        ->assertSeeIn('[data-action-failure-reason]', 'Failed')
        ->assertMissing('[data-action-failure-message]')
        ->assertMissing('[data-action-failure-uncertain]')
        ->assertVisible('[data-action-retry]');
});

test('a member sees why an action failed but not its message, warning or Retry', function (): void {
    $actionRequest = ActionRequest::factory()->described()->needsReconciliation()->create();
    $this->actingAs(User::factory()->member()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id), 'Needs reconciliation')
        ->assertSeeIn('[data-action-failure-reason]', 'Needs reconciliation')
        ->assertMissing('[data-action-failure-message]')
        ->assertMissing('[data-action-failure-uncertain]')
        ->assertMissing('[data-action-retry]');
});

test("a live update with the same status keeps the admin's failure message", function (): void {
    $actionRequest = ActionRequest::factory()->described()->needsReconciliation()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-failure-message]', 'whether it was applied is unknown');

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ], 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));

    $webpage->assertSeeIn('[data-action-failure-message]', 'whether it was applied is unknown')
        ->assertSeeIn('[data-action-failure-uncertain]', 'Outcome unknown — check Sonarr before retrying.')
        ->assertNoSmoke();
});

test('a retry seen live clears the old failure', function (): void {
    $actionRequest = ActionRequest::factory()->described()->needsReconciliation()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-action-failure]');

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'approved',
        'result' => null,
        'updated_at' => now()->toISOString(),
    ], 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'approved'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'approved'));

    $webpage->assertMissing('[data-action-failure]')
        ->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertMissing('[data-action-retry]')
        ->assertNoSmoke();
});

test('a failure seen live brings the admin its message', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-action-failure]');

    // The job records the failure, then broadcasts only the member-safe keys.
    $actionRequest->update([
        'status' => ActionRequestStatus::Failed,
        'result' => [
            'success' => false,
            'reason' => 'needs_reconciliation',
            'message' => 'Sonarr answered the write with a login page, so whether it was applied is unknown.',
            'exception' => ArrWriteUnconfirmed::class,
            'indeterminate' => true,
        ],
    ]);
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ], 3));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-action-failure-message]') !== null"));

    $webpage->assertSeeIn('[data-action-failure-message]', 'whether it was applied is unknown')
        ->assertSeeIn('[data-action-failure-uncertain]', 'Outcome unknown — check Sonarr before retrying.')
        ->assertNoSmoke();
});

test('a broadcast for an unlisted request, or without a result, changes nothing', function (): void {
    $actionRequest = ActionRequest::factory()->described()->needsReconciliation()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-action-row]', 1);

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => 999_999,
        'status' => 'completed',
        'result' => ['success' => true, 'reason' => null, 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ], 3));
    $webpage->script(actionQueueFailureWaitForToastScript(999_999, 'completed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', 999_999, 'completed'));
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'updated_at' => now()->toISOString(),
    ], 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));

    $webpage->assertCount('[data-action-row]', 1)
        ->assertSeeIn('[data-action-failure-message]', 'whether it was applied is unknown')
        ->assertSeeIn('[data-action-failure-uncertain]', 'Outcome unknown — check Sonarr before retrying.')
        ->assertNoSmoke();
});

test('a live failure after missed events replaces the old outcome with the new one', function (): void {
    $actionRequest = ActionRequest::factory()->described()->failed()->create();
    Queue::fake([ExecuteActionRequest::class]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-action-failure-badge="%d"][data-action-failure-tone="danger"]', $actionRequest->id), 'Execution failed')
        ->assertSeeIn('[data-action-failure-message]', 'Sonarr answered 404 Not Found.')
        ->assertMissing('[data-action-failure-uncertain]');

    // While the page's socket was down, the request was retried and failed
    // again with an unknown outcome; only that last failure reaches the page.
    $actionRequest->update([
        'result' => [
            'success' => false,
            'reason' => 'needs_reconciliation',
            'message' => 'The worker was lost mid-run, so whether the delete reached Sonarr is unknown.',
            'exception' => ArrWriteUnconfirmed::class,
            'indeterminate' => true,
        ],
    ]);
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ], 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-action-failure-message]')?.innerText.includes('worker was lost') === true"));

    $webpage->assertSeeIn(sprintf('[data-action-failure-badge="%d"][data-action-failure-tone="warn"]', $actionRequest->id), 'Needs reconciliation')
        ->assertSeeIn('[data-action-failure-uncertain]', 'Outcome unknown — check Sonarr before retrying.')
        ->assertSeeIn('[data-action-failure-message]', 'whether the delete reached Sonarr is unknown')
        ->assertDontSeeIn('[data-action-failure]', 'Sonarr answered 404 Not Found.')
        ->click('[data-action-retry]')
        ->assertSeeIn('[data-confirm-dialog]', 'Retry an action with an unknown outcome?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Failed);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

/**
 * Counts the Action Queue's finished partial reloads that include
 * `requests` (the debounced reload a live failure schedules for admins).
 */
function actionQueueFailureReloadRecorderScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmRequestsReloads = 0;
            document.addEventListener('inertia:finish', (event) => {
                if (event.detail.visit.only.includes('requests')) {
                    window.__mmRequestsReloads += 1;
                }
            });
        })()
    JS;
}

function actionQueueFailureWaitForReloadScript(): string
{
    return confirmDialogWaitUntilScript('window.__mmRequestsReloads > 0');
}

/**
 * @return array<string, mixed>
 */
function actionQueueFailureLiveStatus(ActionRequest $actionRequest, string $status, int $secondsAfterRow): array
{
    return [
        'id' => $actionRequest->id,
        'status' => $status,
        'result' => $status === 'failed' ? ['success' => false, 'reason' => 'execution_failed', 'rejection_reason' => null] : null,
        'updated_at' => $actionRequest->updated_at->addSeconds($secondsAfterRow)->toISOString(),
    ];
}

/**
 * Holds back the answer to the page's next partial reload that names
 * `requests`, as a slow network would: the request is sent and answered at
 * once (it reads the database now), but the page only sees the answer when
 * actionQueueFailureReleaseHeldScript() runs. Its 'start' therefore comes
 * before anything emitted in between: a reload read before that change.
 */
function actionQueueFailureHoldRequestsReloadScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmHeldAnswered = false;
            window.__mmReleaseHeld = () => {};
            let armed = true;
            const setRequestHeader = XMLHttpRequest.prototype.setRequestHeader;
            XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                if (String(name).toLowerCase() === 'x-inertia-partial-data') {
                    this.__mmPartialData = String(value).split(',');
                }
                return setRequestHeader.call(this, name, value);
            };
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (body) {
                if (armed && (this.__mmPartialData ?? []).includes('requests')) {
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

function actionQueueFailureWaitForHeldAnswerScript(): string
{
    return confirmDialogWaitUntilScript('window.__mmHeldAnswered === true');
}

/**
 * Emits a live status change, then at once lets the held reload's answer in
 * (well inside the 500 ms reload debounce the emit may start).
 *
 * @param  array<string, mixed>  $payload
 */
function actionQueueFailureEmitThenReleaseScript(array $payload): string
{
    return sprintf('(async () => { await %s; window.__mmReleaseHeld(); })()', echoEmitScript('members.actions', 'ActionRequestStatusChanged', $payload, 3));
}

test('a reload read before a live failure does not roll the row back', function (int $secondsAfterRow): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    // A member: no admin reload follows the failure, so only the held one reseeds.
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());
    $webpage->script(actionQueueFailureHoldRequestsReloadScript());

    // Refresh reads the Executing row before the failure arrives; its answer
    // lands after it, as a slow reload or a late redirect does.
    $webpage->click('[data-action-refresh]');
    $webpage->script(actionQueueFailureWaitForHeldAnswerScript());
    $webpage->script(actionQueueFailureEmitThenReleaseScript(actionQueueFailureLiveStatus($actionRequest, 'failed', $secondsAfterRow)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());
    $webpage->script(actionQueueFailureRestScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBe(1);

    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
})->with([
    'a later second' => [60],
    'the same second, a later step' => [0],
]);

test('a live status older than the row a reload brings yields to it', function (): void {
    $actionRequest = ActionRequest::factory()->described()->completed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(actionQueueFailureReloadRecorderScript());

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'failed', -3600), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('an older live event arriving late does not undo a newer one', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Approved]);
    // A member: no reload follows the failure, so only the live memory decides.
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'failed', 60), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'executing', 30), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'executing'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'executing'));
    $webpage->script(actionQueueFailureRestScript());

    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('a status for an unlisted request is not remembered for a later reload', function (): void {
    $listed = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());
    $webpage->script(actionQueueFailureHoldRequestsReloadScript());

    // Created after the page loaded: not listed now, listed by the held
    // reload, which reads both rows before either failure arrives.
    $unlisted = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Pending]);
    $webpage->click('[data-action-refresh]');
    $webpage->script(actionQueueFailureWaitForHeldAnswerScript());
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($unlisted, 'failed', 60), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($unlisted->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $unlisted->id, 'failed'));
    $webpage->script(actionQueueFailureEmitThenReleaseScript(actionQueueFailureLiveStatus($listed, 'failed', 60)));
    $webpage->script(actionQueueFailureWaitForToastScript($listed->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());
    $webpage->script(actionQueueFailureRestScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBe(1);

    $webpage->assertVisible(sprintf('[data-action-row="%d"]', $unlisted->id))
        ->assertMissing(sprintf('[data-action-failure-badge="%d"]', $unlisted->id))
        ->assertVisible(sprintf('[data-action-failure-badge="%d"]', $listed->id))
        ->assertNoSmoke();
});

test("an admin's retry is not undone by a failure remembered from a live event", function (): void {
    $actionRequest = ActionRequest::factory()->described()->failed()->create();
    // Faked after the factories: a later Queue::fake replaces the Browser
    // beforeEach fake, and the WebhookEvent factory creates a connection.
    Queue::fake([ExecuteActionRequest::class]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));
    $webpage->script(actionQueueFailureReloadRecorderScript());

    // Newer than the server row by its timestamp.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'failed', 60), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    // The redirect's row (approved, written now) is older by its timestamp
    // than the live failure: the click forgets the memory, and the redirect,
    // sent after the failure arrived, wins over it anyway.
    $webpage->click('[data-action-retry]')
        ->assertSee('Action requeued for execution.')
        ->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);
    Queue::assertPushed(ExecuteActionRequest::class);
});

test("an admin's review of a pending row is not undone by a status remembered from a live event", function (string $button, string $toast, ActionRequestStatus $status): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true]);
    Queue::fake([ExecuteActionRequest::class]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible(sprintf('[data-bulk-select="%d"]', $actionRequest->id));

    // A pending status a minute newer than the row: remembered, row unchanged.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'pending', 60), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'pending'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'pending'));

    // The redirect's row is older than the remembered pending status: only
    // forgetting it on the click lets the review show.
    $webpage->click($button)
        ->assertSee($toast)
        ->assertMissing(sprintf('[data-bulk-select="%d"]', $actionRequest->id))
        ->assertMissing($button)
        ->assertNoSmoke();

    expect($actionRequest->fresh()->status)->toBe($status);
})->with([
    'approve' => ['[data-action-approve]', 'Action approved and queued.', ActionRequestStatus::Approved],
    'decline' => ['[data-action-decline]', 'Action rejected.', ActionRequestStatus::Rejected],
]);

test("an admin's bulk review is not undone by a status remembered from a live event", function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true]);
    Queue::fake([ExecuteActionRequest::class]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible(sprintf('[data-bulk-select="%d"]', $actionRequest->id));

    // A pending status a minute newer than the row: remembered, row unchanged.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'pending', 60), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'pending'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'pending'));
    $webpage->script(actionQueueFailureReloadRecorderScript());

    // The bulk run's reload brings the approved row, older than the
    // remembered pending status: only forgetting it first lets it show.
    $webpage->click(sprintf('[data-bulk-select="%d"]', $actionRequest->id))
        ->click('[data-bulk-approve]')
        ->assertSee('1 approved');
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    $webpage->assertMissing(sprintf('[data-bulk-select="%d"]', $actionRequest->id))
        ->assertNoSmoke();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('a live status with an unreadable timestamp still applies over a remembered one', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    // A member: no reload follows the failure, so the failure stays remembered.
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'failed', 60), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));

    // Cannot be ordered against the remembered failure: a tie, so it applies.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        ...actionQueueFailureLiveStatus($actionRequest, 'executing', 0),
        'updated_at' => 'not-a-timestamp',
    ], 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'executing'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'executing'));

    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

/**
 * A live status change carrying a status version: the row's own version
 * plus $versionsAfterRow (negative for an older change).
 *
 * @return array<string, mixed>
 */
function actionQueueFailureVersionedStatus(ActionRequest $actionRequest, string $status, int $versionsAfterRow, int $secondsAfterRow = 0): array
{
    return [
        ...actionQueueFailureLiveStatus($actionRequest, $status, $secondsAfterRow),
        'status_version' => $actionRequest->status_version + $versionsAfterRow,
    ];
}

/**
 * Rests inside the page well past the Action Queue's 500 ms reload debounce.
 */
function actionQueueFailureRestScript(): string
{
    return '(async () => { await new Promise((resolve) => setTimeout(resolve, 1200)); })()';
}

test('a same-second change by someone else is ordered by its status version, not its lifecycle step', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'failed', 1), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));

    // Another admin retried in the same second: failed → approved, a step back.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'approved', 2), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'approved'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('a live change from a host whose clock runs behind still applies when its version is newer', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'failed', 1, 60), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    // Written later, stamped 30 s earlier by a host whose clock is behind.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'approved', 2, 30), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'approved'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('a live status older than the listed row is ignored, whatever its timestamp says', function (): void {
    // Created first, so the listed row's version has an older one below it.
    ActionRequest::factory()->described()->create();
    $actionRequest = ActionRequest::factory()->described()->completed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'failed', -1, 3600), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureRestScript());

    // Ignored, not applied and then undone: an applied admin failure always
    // schedules a `requests` reload.
    expect($webpage->script('window.__mmRequestsReloads'))->toBe(0);

    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test("a reload read before a live failure keeps it even when the worker's clock runs behind", function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());
    $webpage->script(actionQueueFailureHoldRequestsReloadScript());

    $webpage->click('[data-action-refresh]');
    $webpage->script(actionQueueFailureWaitForHeldAnswerScript());
    // Newer by version, stamped an hour before the row the reload reads.
    $webpage->script(actionQueueFailureEmitThenReleaseScript(actionQueueFailureVersionedStatus($actionRequest, 'failed', 1, -3600)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());
    $webpage->script(actionQueueFailureRestScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBe(1);

    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('after a reload keeps a live failure, one more reload brings the admin its message', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());
    $webpage->script(actionQueueFailureHoldRequestsReloadScript());

    // The held reload reads the Executing row; then the failure is written
    // (the observer numbers it) and broadcast before that answer lands.
    $webpage->click('[data-action-refresh]');
    $webpage->script(actionQueueFailureWaitForHeldAnswerScript());

    $actionRequest->update(['status' => ActionRequestStatus::Failed, 'result' => [
        'success' => false,
        'reason' => 'execution_failed',
        'message' => 'HTTP 404: Sonarr could not find the item — it may already have been removed.',
    ]]);
    $webpage->script(actionQueueFailureEmitThenReleaseScript(actionQueueFailureVersionedStatus($actionRequest, 'failed', 0)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));

    // The held answer keeps the live failure, which carries no message; the
    // next reload, sent after the failure arrived, brings it.
    $webpage->script(confirmDialogWaitUntilScript('window.__mmRequestsReloads >= 2'));

    expect($webpage->script('window.__mmRequestsReloads'))->toBe(2);

    $webpage->assertSeeIn('[data-action-failure-message]', 'HTTP 404: Sonarr could not find the item — it may already have been removed.');

    // Once per live change: no third reload.
    $webpage->script(actionQueueFailureRestScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBe(2);
    $webpage->assertNoSmoke();
});

test('a member whose reload keeps a live failure gets no follow-up reload', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());
    $webpage->script(actionQueueFailureHoldRequestsReloadScript());

    $webpage->click('[data-action-refresh]');
    $webpage->script(actionQueueFailureWaitForHeldAnswerScript());
    $webpage->script(actionQueueFailureEmitThenReleaseScript(actionQueueFailureVersionedStatus($actionRequest, 'failed', 1)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());
    $webpage->script(actionQueueFailureRestScript());

    // The held reload only: a member never gets the admin's message.
    expect($webpage->script('window.__mmRequestsReloads'))->toBe(1);

    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('a kept live failure without a version brings an admin one more reload, not a loop', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());
    $webpage->script(actionQueueFailureHoldRequestsReloadScript());

    // A broadcast from a worker on older code: no status_version. The
    // database row stays Executing, so every reload reads it stale.
    $webpage->click('[data-action-refresh]');
    $webpage->script(actionQueueFailureWaitForHeldAnswerScript());
    $webpage->script(actionQueueFailureEmitThenReleaseScript(actionQueueFailureLiveStatus($actionRequest, 'failed', 60)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->script(confirmDialogWaitUntilScript('window.__mmRequestsReloads >= 2'));
    $webpage->script(actionQueueFailureRestScript());
    $webpage->script(actionQueueFailureRestScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBe(2);
    $webpage->assertNoSmoke();
});

test('a remembered status the server cannot reach yields to a later reload, and live changes apply again', function (): void {
    $actionRequest = ActionRequest::factory()->described()->completed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    // A version far above anything the database holds (a restored database,
    // a bogus payload): it applies, and is remembered.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'failed', 50), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));

    // The admin's reload was sent after it arrived: the server's row wins.
    $webpage->script(actionQueueFailureWaitForReloadScript());
    $webpage->script(actionQueueFailureRestScript());
    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));

    // And a real change is no longer judged against the bogus version.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'approved', 1), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'approved'));

    $webpage->assertSeeIn(sprintf('[data-action-row="%d"]', $actionRequest->id), 'Approved')
        ->assertNoSmoke();
});

test('Refresh forgets a remembered status the server cannot reach', function (): void {
    $actionRequest = ActionRequest::factory()->described()->completed()->create();
    // A member: no reload follows the failure, so only Refresh can heal it.
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', actionQueueFailureVersionedStatus($actionRequest, 'failed', 50), 3));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));

    $webpage->click('[data-action-refresh]');
    $webpage->script(actionQueueFailureWaitForReloadScript());

    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});
