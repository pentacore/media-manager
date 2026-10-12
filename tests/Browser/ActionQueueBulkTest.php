<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([ExecuteActionRequest::class]);
});

test('an admin approves two pending requests in bulk', function (): void {
    $first = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"']);
    $second = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn("[data-action-row=\"{$first->id}\"]", 'Severance')
        ->click("[data-bulk-select=\"{$first->id}\"]")
        ->click("[data-bulk-select=\"{$second->id}\"]")
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-approve]')
        ->assertSee('2 approved')
        ->assertNoSmoke();

    expect($first->fresh()->status)->toBe(ActionRequestStatus::Approved)
        ->and($second->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('an admin rejects in bulk with one shared reason', function (): void {
    $pending = ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-bulk-select-all]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-reject]')
        ->fill('[data-bulk-reject-reason]', 'Not wanted')
        ->click('[data-bulk-reject-confirm]')
        ->assertSee('2 rejected');

    foreach ($pending as $actionRequest) {
        expect($actionRequest->fresh()->result)->toBe(['rejection_reason' => 'Not wanted']);
    }
});

test('changing the status tab clears the selection', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click("[data-bulk-select=\"{$pending->id}\"]")
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-test="tab-pending"]')
        ->assertCount('[data-bulk-bar]', 0);
});

test('a member sees no bulk selection', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertPresent("[data-action-row=\"{$pending->id}\"]")
        ->assertCount('[data-bulk-select]', 0);
});

test('a member reads the reviewer rejection reason on a rejected request', function (): void {
    $rejected = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Rejected,
        'title' => 'Delete series "Severance"',
        'result' => ['rejection_reason' => 'Still watching this one.'],
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('actions.requests.index', ['status' => 'rejected'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn("[data-action-row=\"{$rejected->id}\"]", 'Severance')
        ->click("[data-action-row=\"{$rejected->id}\"]")
        ->assertSeeIn('[data-action-rejection-reason]', 'Still watching this one.');
});

test('the reject dialog closes when the selection empties under it', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click("[data-bulk-select=\"{$pending->id}\"]")
        ->click('[data-bulk-reject]')
        ->assertVisible('[data-bulk-reject-confirm]');

    // Unticking the only row behind the dialog (what a live status event
    // does) empties the selection.
    $webpage->script("document.querySelector('[data-bulk-select=\"{$pending->id}\"]').click()");
    // The dialog's exit animation keeps it in the DOM briefly.
    $webpage->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 250; attempt++) {
                if (!document.querySelector('[data-bulk-reject-confirm]')) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
        })()
    JS);
    $webpage->assertCount('[data-bulk-reject-confirm]', 0);

    expect($pending->fresh()->status)->toBe(ActionRequestStatus::Pending);
});

test('a confirmed bulk reject hands focus to select all, not the page body', function (): void {
    $actionRequests = ActionRequest::factory()->count(3)->create();
    $this->actingAs(User::factory()->admin()->create());

    // Rows are picked by id: the three share a created_at second, so their
    // order on the page is not fixed.
    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-bulk-select="%d"]', $actionRequests[0]->id))
        ->click(sprintf('[data-bulk-select="%d"]', $actionRequests[1]->id))
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-reject]')
        ->fill('[data-bulk-reject-reason]', 'Not wanted')
        ->click('[data-bulk-reject-confirm]')
        ->assertSee('2 rejected');

    // Assert only after the dialog has left the DOM and Reka's restore
    // (a zero-delay timer scheduled at unmount) has run.
    $webpage->script(confirmDialogWaitUntilScript("!document.querySelector('[role=\"dialog\"]')"));
    $webpage->script('new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => setTimeout(resolve, 0)), 0))');
    $webpage->assertScript("document.activeElement !== null && document.activeElement.closest('[data-bulk-select-all]') !== null");
});

test('a bulk reject that resolves with focus still in the dialog hands focus to select all once the dialog has gone', function (): void {
    $actionRequests = ActionRequest::factory()->count(2)->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-bulk-select="%d"]', $actionRequests[0]->id))
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-bulk-reject]')
        ->fill('[data-bulk-reject-reason]', 'Not wanted');

    // Click Confirm and, in the same task, park focus on the reason field. Vue
    // disables the button on its next flush; with focus still on it Chromium
    // would drop it to <body> and skip the dialog-wait branch under test.
    $webpage->script(<<<'JS'
        document.querySelector('[data-bulk-reject-confirm]').click();
        document.querySelector('[data-bulk-reject-reason]').focus();
    JS);
    $webpage->assertSee('1 rejected');

    // Assert only after the dialog has left the DOM and Reka's restore
    // (a zero-delay timer scheduled at unmount) has run.
    $webpage->script(confirmDialogWaitUntilScript("!document.querySelector('[role=\"dialog\"]')"));
    $webpage->script('new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => setTimeout(resolve, 0)), 0))');
    $webpage->assertScript("document.activeElement !== null && document.activeElement.closest('[data-bulk-select-all]') !== null");
});

/**
 * Hands an ActionRequestStatusChanged broadcast to the page's members.actions
 * listeners. Tests broadcast through the null driver, so this emits on the
 * pusher-js channel Echo subscribed to; it throws when nothing subscribed.
 *
 * @param  array<string, mixed>  $payload
 */
function actionQueueBulkEmitStatusScript(array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const channel = window.Pusher?.instances?.[0]?.channel('private-members.actions');
            if (!channel) {
                throw new Error('The page has not subscribed to members.actions.');
            }
            channel.emit('ActionRequestStatusChanged', {$json});
        })()
    JS;
}

test('the sidebar pending badge drops by the requests a bulk review settled', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3')
        ->click(sprintf('[data-bulk-select="%d"]', $pending[0]->id))
        ->click(sprintf('[data-bulk-select="%d"]', $pending[1]->id))
        ->click('[data-bulk-approve]')
        ->assertSee('2 approved')
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '1')
        ->assertNoSmoke();
});

test('a request settled elsewhere lowers the badge although this tab never saw it created', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3');

    $webpage->script(actionQueueBulkEmitStatusScript([
        'id' => $pending[0]->id,
        'status' => 'approved',
        'result' => null,
        'updated_at' => $pending[0]->updated_at->addSeconds(5)->toISOString(),
        'pending_count' => 2,
    ]));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-nav-badge=\"Action Queue\"]')?.innerText.trim() === '2'"));

    $webpage->assertSeeIn('[data-nav-badge="Action Queue"]', '2')
        ->assertNoSmoke();
});
