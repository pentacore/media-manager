<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkSchedule;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');

    foreach (['gpt-5-nano', 'gpt-5-mini', 'gpt-judge'] as $model) {
        AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model, 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
    }

    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-5-nano')->create();
    AiTaskModel::factory()->task(AiTask::BenchmarkJudge)->selecting('openai', 'gpt-judge')->create();
});

test('an admin creates a weekly schedule with a preview of the tier models', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['tags' => ['anime']]);
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['tags' => ['tv']]);

    visit(route('admin.benchmarks.schedules.create', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-slot="breadcrumb-list"]', 'New schedule')
        ->fill('[data-benchmark-schedule-name]', 'Weekly anime titles')
        ->click('[data-benchmark-schedule-task]')
        ->click('[role="option"]:has-text("Conversation titles")')
        ->assertSeeIn('[data-benchmark-schedule-case-count]', '2 cases')
        ->click('[data-benchmark-schedule-tag="anime"]')
        ->assertSeeIn('[data-benchmark-schedule-case-count]', '1 case')
        ->assertSeeIn('[data-benchmark-schedule-candidates]', 'gpt-5-nano')
        ->click('[data-benchmark-schedule-frequency]')
        ->click('[role="option"]:has-text("Weekly")')
        ->assertVisible('[data-benchmark-schedule-weekday]')
        ->assertMissing('[data-benchmark-schedule-day]')
        ->fill('[data-benchmark-schedule-time]', '04:30')
        ->fill('[data-benchmark-schedule-cap]', '0.000001')
        ->click('[data-benchmark-schedule-estimate-button]')
        ->assertVisible('[data-benchmark-schedule-over-cap]')
        ->fill('[data-benchmark-schedule-cap]', '5')
        ->click('[data-benchmark-schedule-save]')
        ->assertPathIs(route('admin.benchmarks.schedules.index', absolute: false))
        ->assertSeeIn('[data-benchmark-schedule-row]', 'Weekly anime titles')
        ->assertSeeIn('[data-benchmark-schedule-row]', 'Weekly on Monday at 04:30');

    expect(BenchmarkSchedule::query()->sole()->case_tags)->toBe(['anime']);
});

test('the index toggles, runs now and deletes a schedule', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['name' => 'Nightly titles']);

    $webpage = visit(route('admin.benchmarks.schedules.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-benchmark-schedule-row="%d"] [data-benchmark-schedule-toggle] button', $benchmarkSchedule->id))
        ->assertSee('Schedule paused.');

    expect($benchmarkSchedule->fresh()->enabled)->toBeFalse();

    $webpage->click(sprintf('[data-benchmark-schedule-row="%d"] [data-benchmark-schedule-run]', $benchmarkSchedule->id))
        ->assertSee('No Conversation titles cases match this schedule.')
        ->assertSeeIn('[data-benchmark-schedule-outcome]', 'Skipped: no cases')
        ->click(sprintf('[data-benchmark-schedule-row="%d"] [data-benchmark-schedule-delete]', $benchmarkSchedule->id))
        ->click('[data-confirm-accept]')
        ->assertSee('No benchmark schedules yet.');
});

test('the runs index links to the schedules', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.runs.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-benchmark-schedules-link]')
        ->assertPathIs(route('admin.benchmarks.schedules.index', absolute: false));
});

test('an admin creates a monthly schedule with a day of the month', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    visit(route('admin.benchmarks.schedules.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-benchmark-schedule-name]', 'Monthly titles')
        ->click('[data-benchmark-schedule-task]')
        ->click('[role="option"]:has-text("Conversation titles")')
        ->click('[data-benchmark-schedule-frequency]')
        ->click('[role="option"]:has-text("Monthly")')
        ->assertVisible('[data-benchmark-schedule-day]')
        ->assertMissing('[data-benchmark-schedule-weekday]')
        ->fill('[data-benchmark-schedule-day]', '15')
        ->click('[data-benchmark-schedule-save]')
        ->assertPathIs(route('admin.benchmarks.schedules.index', absolute: false))
        ->assertSeeIn('[data-benchmark-schedule-row]', 'Monthly on day 15 at');

    expect(BenchmarkSchedule::query()->sole()->day_of_month)->toBe(15);
});
