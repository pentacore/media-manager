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

function actionQueueSheetOpenScript(): string
{
    return confirmDialogWaitUntilScript('!!document.querySelector("[data-action-sheet]") && document.querySelector("[data-action-sheet]").getAnimations().length === 0');
}

function actionQueueSheetClosedScript(): string
{
    return confirmDialogWaitUntilScript('!document.querySelector("[data-action-sheet]")');
}

/**
 * Two animation frames: long enough for a keypress or a sheet opened in
 * onMounted to have taken effect, without a timed sleep.
 */
function actionQueueSheetTwoFramesScript(): string
{
    return '(async () => { await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))); })()';
}

/**
 * Holds the first Inertia POST the page sends (an approve, reject or retry)
 * until `window.__mmReleasePost()` runs; `__mmPostAnswered` turns true once
 * its answer is in and held.
 */
function actionQueueSheetHoldPostScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmPostAnswered = false;
            window.__mmReleasePost = () => {};
            let armed = true;
            const open = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, ...rest) {
                this.__mmMethod = String(method).toUpperCase();
                return open.call(this, method, ...rest);
            };
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (body) {
                if (armed && this.__mmMethod === 'POST') {
                    armed = false;
                    const onload = this.onload;
                    this.onload = (...args) => {
                        window.__mmReleasePost = () => onload.apply(this, args);
                        window.__mmPostAnswered = true;
                    };
                }
                return send.call(this, body);
            };
        })()
    JS;
}

/**
 * The focused element is neither the sheet's review buttons nor anything
 * else that Enter would activate.
 */
function actionQueueSheetFocusOnTitleScript(): string
{
    return '(() => { const active = document.activeElement; return !!active && active.matches("[data-action-sheet] [data-action-title]") && !active.closest("button, a"); })()';
}

test('a deep link to a request past the first page opens it in the sheet on a phone', function (): void {
    $oldest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true, 'title' => 'Monitor series "Severance"', 'created_at' => now()->subDay()]);
    ActionRequest::factory()->count(29)->sequence(fn ($sequence): array => [
        'title' => sprintf('Monitor series "Example Show %02d"', $sequence->index),
        'created_at' => now()->subMinutes($sequence->index),
    ])->create(['status' => ActionRequestStatus::Completed]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', ['request' => $oldest->id], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueSheetOpenScript());

    $webpage->assertCount(sprintf('[data-action-card="%d"]', $oldest->id), 0)
        ->assertSeeIn('[data-action-sheet] [data-action-title]', 'Severance')
        ->click('[data-action-sheet] [data-action-approve]')
        ->assertSee('Action approved and queued.')
        ->assertMissing('[data-action-sheet] [data-action-approve]')
        ->assertVisible('[data-action-sheet]');

    expect($oldest->fresh()->status)->toBe(ActionRequestStatus::Approved);

    // Its card is not on this page, so closing hands focus to the heading.
    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueueSheetClosedScript());
    $webpage->script(confirmDialogWaitUntilScript('document.activeElement?.tagName === "H1" && !new URL(window.location.href).searchParams.has("request")'));
    $webpage->assertScript('document.activeElement?.tagName', 'H1')
        ->assertNoSmoke();
});

test('the sheet opens on its title, so Enter never approves', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $actionRequest->id));
    $webpage->script(actionQueueSheetOpenScript());

    $webpage->assertScript(actionQueueSheetFocusOnTitleScript(), true)
        ->assertScript('document.querySelector("[data-action-sheet] [data-action-title]").textContent.includes("Severance")', true)
        ->assertScript('document.getElementById(document.querySelector("[data-action-sheet]").getAttribute("aria-describedby"))?.textContent.includes("Approve or decline it at the bottom.") ?? false', true);
    $webpage->keys('[data-action-sheet] [data-action-title]', 'Enter');
    $webpage->script(actionQueueSheetTwoFramesScript());
    $webpage->assertVisible('[data-action-sheet] [data-action-approve]');

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Pending);
});

test('a deep-linked sheet opens on its title, so Enter never approves', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true, 'title' => 'Monitor series "Severance"']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', ['request' => $actionRequest->id], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueSheetOpenScript());

    $webpage->assertScript(actionQueueSheetFocusOnTitleScript(), true);
    $webpage->keys('[data-action-sheet] [data-action-title]', 'Enter');
    $webpage->script(actionQueueSheetTwoFramesScript());
    $webpage->assertVisible('[data-action-sheet] [data-action-approve]');

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Pending);
});

test('the sheet describes only the review the shown action offers', function (): void {
    $completed = ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed, 'title' => 'Monitor series "Severance"']);
    $failed = ActionRequest::factory()->described()->failed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $describedBy = '(document.getElementById(document.querySelector("[data-action-sheet]").getAttribute("aria-describedby"))?.textContent ?? "")';

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $completed->id));
    $webpage->script(actionQueueSheetOpenScript());
    $webpage->assertScript($describedBy.'.includes("its outcome.")', true)
        ->assertScript($describedBy.'.includes("Approve")', false)
        ->assertScript($describedBy.'.includes("Retry")', false);
    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueueSheetClosedScript());

    $webpage->click(sprintf('[data-action-card-open="%d"]', $failed->id));
    $webpage->script(actionQueueSheetOpenScript());
    $webpage->assertScript($describedBy.'.includes("Retry it at the bottom.")', true)
        ->assertScript($describedBy.'.includes("Approve")', false)
        ->assertNoSmoke();
});

test('closing the sheet after the screen widened past the card list focuses the heading', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed, 'title' => 'Monitor series "Severance"']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $actionRequest->id));
    $webpage->script(actionQueueSheetOpenScript());

    // A phone turned to landscape: the card list is display:none from sm up.
    $webpage->resize(812, 375);
    $webpage->assertScript(sprintf('document.querySelector("[data-action-card-open=\"%d\"]").getClientRects().length', $actionRequest->id), 0);
    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueueSheetClosedScript());
    $webpage->script(confirmDialogWaitUntilScript('document.activeElement !== document.body'));

    $webpage->assertScript('document.activeElement?.tagName', 'H1')
        ->assertNoSmoke();
});

test('a review still in flight when a deep-linked sheet closes does not bring the deep link back', function (): void {
    $older = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'requires_approval' => true, 'title' => 'Monitor series "Severance"', 'created_at' => now()->subHour()]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', ['request' => $older->id], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(actionQueueSheetOpenScript());
    $webpage->script('(() => { window.__mmHistoryLength = history.length; })()');
    $webpage->script(actionQueueSheetHoldPostScript());

    $webpage->click('[data-action-sheet] [data-action-approve]');
    $webpage->click('[data-action-sheet] [data-sheet-close]');
    $webpage->script(actionQueueSheetClosedScript());
    $webpage->script(confirmDialogWaitUntilScript('!new URL(window.location.href).searchParams.has("request") && window.__mmPostAnswered'));
    $webpage->script('window.__mmReleasePost()');
    $webpage->assertSee('Action approved and queued.');
    $webpage->script(actionQueueSheetTwoFramesScript());

    $webpage->assertScript('new URL(window.location.href).searchParams.has("request")', false)
        ->assertScript('history.length === window.__mmHistoryLength', true)
        ->assertScript('(history.state?.page?.props?.preselect ?? null) === null', true)
        ->assertMissing('[data-action-sheet]')
        ->assertNoSmoke();
    expect($older->fresh()->status)->toBe(ActionRequestStatus::Approved);
});
