<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-tier', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-tier')->create();
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
});

test('the command launches every due schedule and skips the rest', function (): void {
    $due = BenchmarkSchedule::factory()->forTask(AiTask::Title)->due()->create();
    $otherDue = BenchmarkSchedule::factory()->forTask(AiTask::Title)->due()->create();
    BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['next_run_at' => now()->addHour()]);
    BenchmarkSchedule::factory()->forTask(AiTask::Title)->disabled()->create();

    $this->artisan('benchmark:run-due-schedules')->assertSuccessful();

    expect(BenchmarkRun::query()->pluck('benchmark_schedule_id')->sort()->values()->all())->toBe([$due->id, $otherDue->id]);
});

test('a failing schedule does not stop the next one', function (): void {
    $broken = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['next_run_at' => now()->subMinutes(10)]);
    $healthy = BenchmarkSchedule::factory()->forTask(AiTask::Title)->due()->create();
    DB::table('benchmark_schedules')->where('id', $broken->id)->update(['task' => 'retired_task']);

    $this->artisan('benchmark:run-due-schedules')->assertSuccessful();

    expect(BenchmarkRun::query()->pluck('benchmark_schedule_id')->all())->toBe([$healthy->id])
        ->and(DB::table('benchmark_schedules')->where('id', $broken->id)->value('last_outcome'))->toBe('failed');
});

test('the command does nothing while AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    $due = BenchmarkSchedule::factory()->forTask(AiTask::Title)->due()->create();
    $nextRunAt = $due->next_run_at->toDateTimeString();

    $this->artisan('benchmark:run-due-schedules')->expectsOutputToContain('AI is disabled')->assertSuccessful();

    expect(BenchmarkRun::query()->count())->toBe(0)
        ->and($due->fresh()->next_run_at->toDateTimeString())->toBe($nextRunAt)
        ->and($due->fresh()->last_outcome)->toBeNull();
});

test('a schedule whose alert throws does not stop the next one', function (): void {
    $this->mock(AdminNotifier::class)->shouldReceive('send')->andThrow(new RuntimeException('Notifier down'));
    $noCases = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['next_run_at' => now()->subMinutes(10), 'case_tags' => ['missing']]);
    $healthy = BenchmarkSchedule::factory()->forTask(AiTask::Title)->due()->create();

    $this->artisan('benchmark:run-due-schedules')->assertSuccessful();

    expect(BenchmarkRun::query()->pluck('benchmark_schedule_id')->all())->toBe([$healthy->id])
        ->and($noCases->fresh()->next_run_at->isFuture())->toBeTrue();
});

test('a very long skip message is stored within the column length', function (): void {
    $tags = array_map(static fn (int $i): string => str_repeat('t', 55).$i, range(1, 20));
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->due()->create(['case_tags' => $tags]);

    $this->artisan('benchmark:run-due-schedules')->assertSuccessful();

    expect(mb_strlen((string) $benchmarkSchedule->fresh()->last_message))->toBeLessThanOrEqual(255)
        ->and($benchmarkSchedule->fresh()->last_outcome->value)->toBe('skipped_no_cases');
});
