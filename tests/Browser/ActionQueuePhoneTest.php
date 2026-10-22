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

test('action cards fit a phone and show the type, target, status, age and failure reason', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "'.str_repeat('Severance', 8).'"']);
    $failed = ActionRequest::factory()->described()->failed()->create();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertVisible(sprintf('[data-action-card="%d"]', $pending->id))
        ->assertSeeIn(sprintf('[data-action-card="%d"] [data-action-card-title]', $pending->id), 'SeveranceSeverance')
        ->assertSeeIn(sprintf('[data-action-card="%d"]', $pending->id), "act_{$pending->id}")
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
