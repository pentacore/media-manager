<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
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
