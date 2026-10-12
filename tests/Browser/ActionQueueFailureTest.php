<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Arr\ArrWriteUnconfirmed;
use Illuminate\Support\Facades\Queue;

/**
 * Hands a broadcast to the page's members.actions listeners. Tests broadcast
 * through the null driver, so nothing arrives over the socket; this emits on
 * the pusher-js channel Echo subscribed to, which runs exactly the callbacks
 * a real event would. Throws when the page never subscribed.
 *
 * @param  array<string, mixed>  $payload
 */
function actionQueueFailureEmitScript(string $event, array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const channel = window.Pusher?.instances?.[0]?.channel('private-members.actions');
            if (!channel) {
                throw new Error('The page has not subscribed to members.actions.');
            }
            channel.emit('{$event}', {$json});
        })()
    JS;
}

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

    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ]));
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

    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'approved',
        'result' => null,
        'updated_at' => now()->toISOString(),
    ]));
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
    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ]));
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

    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', [
        'id' => 999_999,
        'status' => 'completed',
        'result' => ['success' => true, 'reason' => null, 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ]));
    $webpage->script(actionQueueFailureWaitForToastScript(999_999, 'completed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', 999_999, 'completed'));
    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'updated_at' => now()->toISOString(),
    ]));
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
    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', [
        'id' => $actionRequest->id,
        'status' => 'failed',
        'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'rejection_reason' => null],
        'updated_at' => now()->toISOString(),
    ]));
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
 * Waits until the page has bound its ActionRequestStatusChanged listener on
 * members.actions (pusher-js keeps bound callbacks under `_<event>`), so an
 * emit cannot run before the page listens.
 */
function actionQueueFailureWaitForListenerScript(): string
{
    return confirmDialogWaitUntilScript("(window.Pusher?.instances?.[0]?.channel('private-members.actions')?.callbacks?._callbacks?._ActionRequestStatusChanged ?? []).length > 0");
}

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

test('a reload read before a live failure does not roll the row back', function (int $secondsAfterRow): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id));
    $webpage->script(actionQueueFailureWaitForListenerScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    // The database row stays Executing, so the reload this failure schedules
    // reads a row older than the broadcast — as a late approve redirect does.
    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'failed', $secondsAfterRow)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

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
    $webpage->script(actionQueueFailureWaitForListenerScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'failed', -3600)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    $webpage->assertMissing(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('an older live event arriving late does not undo a newer one', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Approved]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(actionQueueFailureWaitForListenerScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'failed', 60)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'failed'));
    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', actionQueueFailureLiveStatus($actionRequest, 'executing', 30)));
    $webpage->script(actionQueueFailureWaitForToastScript($actionRequest->id, 'executing'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $actionRequest->id, 'executing'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    $webpage->assertVisible(sprintf('[data-action-failure-badge="%d"]', $actionRequest->id))
        ->assertNoSmoke();
});

test('a status for an unlisted request is not remembered for a later reload', function (): void {
    $listed = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Executing]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(actionQueueFailureWaitForListenerScript());
    $webpage->script(actionQueueFailureReloadRecorderScript());

    // Created after the page loaded: not listed now, listed by the next reload.
    $unlisted = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Pending]);
    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', actionQueueFailureLiveStatus($unlisted, 'failed', 60)));
    $webpage->script(actionQueueFailureWaitForToastScript($unlisted->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $unlisted->id, 'failed'));
    $webpage->script(actionQueueFailureEmitScript('ActionRequestStatusChanged', actionQueueFailureLiveStatus($listed, 'failed', 60)));
    $webpage->script(actionQueueFailureWaitForToastScript($listed->id, 'failed'));
    $webpage->assertSee(sprintf('Request #%d is now %s', $listed->id, 'failed'));
    $webpage->script(actionQueueFailureWaitForReloadScript());

    expect($webpage->script('window.__mmRequestsReloads'))->toBeGreaterThan(0);

    $webpage->assertVisible(sprintf('[data-action-row="%d"]', $unlisted->id))
        ->assertMissing(sprintf('[data-action-failure-badge="%d"]', $unlisted->id))
        ->assertVisible(sprintf('[data-action-failure-badge="%d"]', $listed->id))
        ->assertNoSmoke();
});
