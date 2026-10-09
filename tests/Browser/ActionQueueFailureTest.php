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
