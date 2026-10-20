<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkScheduleFrequency;
use App\Models\ActivityLog;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\User;
use App\Notifications\ScheduledBenchmarkAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Cache::flush();
    Queue::fake();
    Notification::fake();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-tier', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-tier')->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function schedulePayload(array $overrides = []): array
{
    return [
        'name' => 'Nightly titles',
        'task' => AiTask::Title->value,
        'case_tags' => ['anime'],
        'judge' => ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'provider_default'],
        'cost_cap_usd' => 2.5,
        'frequency' => 'weekly',
        'time_of_day' => '04:15',
        'weekday' => 3,
        'day_of_month' => 12,
        'pass_rate_threshold' => 15,
        'score_threshold' => 10,
        'enabled' => true,
        ...$overrides,
    ];
}

test('schedule pages are admin only', function (): void {
    $benchmarkSchedule = BenchmarkSchedule::factory()->create();
    $this->actingAs(User::factory()->member()->create());

    $this->get(route('admin.benchmarks.schedules.index'))->assertForbidden();
    $this->get(route('admin.benchmarks.schedules.create'))->assertForbidden();
    $this->post(route('admin.benchmarks.schedules.store'), [])->assertForbidden();
    $this->get(route('admin.benchmarks.schedules.edit', $benchmarkSchedule))->assertForbidden();
    $this->put(route('admin.benchmarks.schedules.update', $benchmarkSchedule), [])->assertForbidden();
    $this->patch(route('admin.benchmarks.schedules.toggle', $benchmarkSchedule), [])->assertForbidden();
    $this->post(route('admin.benchmarks.schedules.run', $benchmarkSchedule))->assertForbidden();
    $this->delete(route('admin.benchmarks.schedules.destroy', $benchmarkSchedule))->assertForbidden();
});

test('schedule pages are hidden when ai is off', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.benchmarks.schedules.index'))->assertNotFound();
});

test('the index lists schedules with their cadence, outcome and latest run', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->weekly(1)->create([
        'name' => 'Weekly titles', 'last_outcome' => 'skipped_over_cap', 'last_message' => 'Too pricey.',
    ]);
    $benchmarkRun = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->completed()->create(['name' => 'Weekly titles · 2026-10-19']);

    $this->get(route('admin.benchmarks.schedules.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Schedules/Index')
            ->where('schedules.data.0.name', 'Weekly titles')
            ->where('schedules.data.0.task_label', 'Conversation titles')
            ->where('schedules.data.0.cadence', 'Weekly on Monday at 03:00')
            ->where('schedules.data.0.last_outcome_label', 'Skipped: over cost cap')
            ->where('schedules.data.0.last_message', 'Too pricey.')
            ->where('schedules.data.0.latest_run.id', $benchmarkRun->id));
});

test('the create form offers benchmarkable tasks, cases, tiers and the judge default', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $case = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['tags' => ['anime']]);

    $this->get(route('admin.benchmarks.schedules.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Schedules/Form')
            ->where('schedule', null)
            ->where('cases.0.id', $case->id)
            ->where('cases.0.tags', ['anime'])
            ->where('currentTiers.title.0.model', 'gpt-tier')
            ->has('judgeDefault.model')
            ->where('frequencies', fn ($frequencies): bool => collect($frequencies)->pluck('value')->all() === ['daily', 'weekly', 'monthly'])
            ->where('tasks', fn ($tasks): bool => collect($tasks)->pluck('value')->doesntContain('benchmark_judge')));
});

test('storing a schedule saves it, clears the irrelevant day and sets its next run', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post(route('admin.benchmarks.schedules.store'), schedulePayload())
        ->assertRedirect(route('admin.benchmarks.schedules.index'));

    $benchmarkSchedule = BenchmarkSchedule::query()->sole();
    expect($benchmarkSchedule->frequency)->toBe(BenchmarkScheduleFrequency::Weekly)
        ->and($benchmarkSchedule->weekday)->toBe(3)
        ->and($benchmarkSchedule->day_of_month)->toBeNull()
        ->and($benchmarkSchedule->case_tags)->toBe(['anime'])
        ->and($benchmarkSchedule->created_by)->toBe($admin->id)
        ->and($benchmarkSchedule->next_run_at?->isFuture())->toBeTrue()
        ->and($benchmarkSchedule->next_run_at->dayOfWeekIso)->toBe(3)
        ->and(ActivityLog::query()->where('action', 'benchmark_schedule.created')->exists())->toBeTrue();
});

test('a disabled schedule has no next run', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.benchmarks.schedules.store'), schedulePayload(['enabled' => false, 'frequency' => 'daily']))->assertRedirect();

    $benchmarkSchedule = BenchmarkSchedule::query()->sole();
    expect($benchmarkSchedule->next_run_at)->toBeNull()
        ->and($benchmarkSchedule->weekday)->toBeNull()
        ->and($benchmarkSchedule->day_of_month)->toBeNull();
});

test('schedule validation', function (array $overrides, string $field): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.benchmarks.schedules.store'), schedulePayload($overrides))->assertSessionHasErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'judge task' => [['task' => 'benchmark_judge'], 'task'],
    'unknown task' => [['task' => 'nope'], 'task'],
    'zero cap' => [['cost_cap_usd' => 0], 'cost_cap_usd'],
    'huge cap' => [['cost_cap_usd' => 1001], 'cost_cap_usd'],
    'bad frequency' => [['frequency' => 'hourly'], 'frequency'],
    'bad time' => [['time_of_day' => '25:00'], 'time_of_day'],
    'weekly without weekday' => [['frequency' => 'weekly', 'weekday' => null], 'weekday'],
    'weekday out of range' => [['frequency' => 'weekly', 'weekday' => 8], 'weekday'],
    'monthly without day' => [['frequency' => 'monthly', 'day_of_month' => null], 'day_of_month'],
    'day 29' => [['frequency' => 'monthly', 'day_of_month' => 29], 'day_of_month'],
    'zero threshold' => [['pass_rate_threshold' => 0], 'pass_rate_threshold'],
    'threshold over 100' => [['score_threshold' => 101], 'score_threshold'],
    'unpriced judge' => [['judge' => ['provider' => 'openai', 'model' => 'unpriced', 'reasoning' => null]], 'judge.model'],
    'duplicate tags' => [['case_tags' => ['a', 'a']], 'case_tags.1'],
]);

test('updating a schedule recomputes its next run', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['next_run_at' => now()->addDays(20)]);

    $this->put(route('admin.benchmarks.schedules.update', $benchmarkSchedule), schedulePayload(['frequency' => 'daily']))
        ->assertRedirect(route('admin.benchmarks.schedules.index'));

    expect($benchmarkSchedule->fresh()->next_run_at->lessThanOrEqualTo(now()->addDay()))->toBeTrue()
        ->and($benchmarkSchedule->fresh()->name)->toBe('Nightly titles');
});

test('the edit form carries the schedule', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->monthly(5)->create(['cost_cap_usd' => 3]);

    $this->get(route('admin.benchmarks.schedules.edit', $benchmarkSchedule))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('schedule.id', $benchmarkSchedule->id)
            ->where('schedule.frequency', 'monthly')
            ->where('schedule.day_of_month', 5)
            ->where('schedule.cost_cap_usd', 3));
});

test('toggling sets and clears the next run', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->create();

    $this->patch(route('admin.benchmarks.schedules.toggle', $benchmarkSchedule), ['enabled' => false])->assertRedirect();
    expect($benchmarkSchedule->fresh()->enabled)->toBeFalse()->and($benchmarkSchedule->fresh()->next_run_at)->toBeNull();

    $this->patch(route('admin.benchmarks.schedules.toggle', $benchmarkSchedule), ['enabled' => true])->assertRedirect();
    expect($benchmarkSchedule->fresh()->enabled)->toBeTrue()->and($benchmarkSchedule->fresh()->next_run_at?->isFuture())->toBeTrue();
});

test('run now redirects to the started run', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create();
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $response = $this->post(route('admin.benchmarks.schedules.run', $benchmarkSchedule));

    $benchmarkRun = BenchmarkRun::query()->sole();
    $response->assertRedirect(route('admin.benchmarks.runs.show', $benchmarkRun));
});

test('run now while a run is going comes back with an info toast', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create();
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->running()->create(['name' => 'Busy']);

    $this->from(route('admin.benchmarks.schedules.index'))
        ->post(route('admin.benchmarks.schedules.run', $benchmarkSchedule))
        ->assertRedirect(route('admin.benchmarks.schedules.index'))
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', 'The previous run "Busy" is still going.');

    expect(BenchmarkRun::query()->count())->toBe(1);
});

test('deleting a schedule keeps its runs', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkSchedule = BenchmarkSchedule::factory()->create();
    $benchmarkRun = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->completed()->create();

    $this->delete(route('admin.benchmarks.schedules.destroy', $benchmarkSchedule))->assertRedirect(route('admin.benchmarks.schedules.index'));

    expect(BenchmarkSchedule::query()->count())->toBe(0)
        ->and($benchmarkRun->fresh()->benchmark_schedule_id)->toBeNull();
});

test('the skipped alert links to the schedules index', function (): void {
    expect(ScheduledBenchmarkAlert::skipped(BenchmarkSchedule::factory()->create(), 'x')->link)
        ->toBe(route('admin.benchmarks.schedules.index', absolute: false));
});
