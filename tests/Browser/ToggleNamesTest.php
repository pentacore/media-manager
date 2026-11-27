<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\BenchmarkSchedule;
use App\Models\NotificationDestination;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;

/*
 * Switches outside settings rows: each one is named by its column, heading or
 * label instead of its on/off text, which only repeats aria-checked.
 */

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());
});

test('the approval rule switches are named by their rule and column', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    visit(route('actions.rules.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript(switchNameScript('[data-rule-enabled-toggle="delete_series"]'), 'Delete series from Sonarr: enabled')
        ->assertScript(switchNameScript('[data-rule-approval-toggle="delete_series"]'), 'Delete series from Sonarr: requires approval');
});

test('a benchmark schedule switch is named by its schedule on the index and by its heading on the form', function (): void {
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['name' => 'Nightly titles']);

    visit(route('admin.benchmarks.schedules.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript(switchNameScript(sprintf('[data-benchmark-schedule-row="%d"] [data-benchmark-schedule-toggle] [role="switch"]', $benchmarkSchedule->id)), 'Nightly titles: enabled');

    visit(route('admin.benchmarks.schedules.create', absolute: false))
        ->assertNoSmoke()
        ->assertScript(switchNameScript('[data-benchmark-schedule-enabled] [role="switch"]'), 'Enabled');
});

test('the webhook log capture switch is named by its heading', function (): void {
    visit(route('admin.webhook-log.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript(switchNameScript('[data-webhook-capture-toggle]'), 'Capture & store');
});

test('the destination enabled switch is named by its label in the edit dialog', function (): void {
    NotificationDestination::factory()->webhook()->create(['label' => 'Hooks']);

    $webpage = visit(route('admin.notification-destinations.index', absolute: false))
        ->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click('Edit')
        ->assertVisible('[data-destination-enabled="edit"]')
        ->assertScript(switchNameScript('[data-destination-enabled="edit"]'), 'Enabled');
});
