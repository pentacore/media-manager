<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Jobs\RunBenchmarkCase;
use App\Models\AiModelPrice;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;
use App\Services\Benchmark\BenchmarkRunLauncher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

/**
 * @return list<array{provider: string, model: string, reasoning: string}>
 */
function launcherCandidates(): array
{
    return [
        ['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'provider_default'],
        ['provider' => 'anthropic', 'model' => 'claude-candidate', 'reasoning' => 'high'],
    ];
}

test('launching creates a running run with one pending result per case and candidate', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-candidate', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    $user = User::factory()->admin()->create();
    $decision = BenchmarkCase::factory()->create(['name' => 'Stuck download']);
    $title = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Expanse title']);
    $judge = ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'provider_default'];

    $benchmarkRun = resolve(BenchmarkRunLauncher::class)->launch('Nightly', collect([$decision, $title]), launcherCandidates(), $judge, 1.5, $user);

    expect($benchmarkRun->status)->toBe(BenchmarkRunStatus::Running)
        ->and($benchmarkRun->name)->toBe('Nightly')
        ->and($benchmarkRun->started_at)->not->toBeNull()
        ->and($benchmarkRun->finished_at)->toBeNull()
        ->and($benchmarkRun->candidates)->toBe(launcherCandidates())
        ->and($benchmarkRun->judge)->toBe($judge)
        ->and($benchmarkRun->case_ids)->toBe([$decision->id, $title->id])
        ->and((float) $benchmarkRun->estimate_usd)->toBe(0.016)
        ->and((float) $benchmarkRun->cost_cap_usd)->toBe(1.5)
        ->and((float) $benchmarkRun->cost_usd)->toBe(0.0)
        ->and($benchmarkRun->created_by)->toBe($user->id);

    $results = $benchmarkRun->results()->orderBy('id')->get();

    expect($results)->toHaveCount(4)
        ->and($results->pluck('status')->unique()->all())->toBe([BenchmarkResultStatus::Pending])
        ->and($results->map(fn (BenchmarkResult $benchmarkResult): array => [
            $benchmarkResult->benchmark_case_id,
            $benchmarkResult->case_name,
            $benchmarkResult->case_task,
            $benchmarkResult->candidate_index,
        ])->all())->toBe([
            [$decision->id, 'Stuck download', 'decision', 0],
            [$decision->id, 'Stuck download', 'decision', 1],
            [$title->id, 'Expanse title', 'title', 0],
            [$title->id, 'Expanse title', 'title', 1],
        ]);

    Queue::assertPushed(RunBenchmarkCase::class, 4);

    foreach ($results as $result) {
        Queue::assertPushed(RunBenchmarkCase::class, fn (RunBenchmarkCase $runBenchmarkCase): bool => $runBenchmarkCase->resultId === $result->id
            && ! $runBenchmarkCase->judgeOnly);
    }
});

test('a launch without a cost cap leaves the cap empty', function (): void {
    $benchmarkRun = resolve(BenchmarkRunLauncher::class)->launch(
        'Uncapped',
        collect([BenchmarkCase::factory()->create()]),
        launcherCandidates(),
        ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'provider_default'],
        null,
        User::factory()->admin()->create(),
    );

    expect($benchmarkRun->cost_cap_usd)->toBeNull();
    Queue::assertPushed(RunBenchmarkCase::class, 2);
});

test('cancelling a run skips its pending results and keeps finished ones', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->create();
    $pending = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    $finished = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 1)->scored(1.0, true)->create();

    resolve(BenchmarkRunLauncher::class)->cancel($benchmarkRun);

    expect($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Cancelled)
        ->and($benchmarkRun->fresh()->finished_at)->not->toBeNull()
        ->and($pending->fresh()->status)->toBe(BenchmarkResultStatus::Skipped)
        ->and($finished->fresh()->status)->toBe(BenchmarkResultStatus::Ok);
});

test('cancelling a finished run changes nothing', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->completed()->create();
    $finishedAt = $benchmarkRun->finished_at;

    resolve(BenchmarkRunLauncher::class)->cancel($benchmarkRun);

    expect($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and($benchmarkRun->fresh()->finished_at?->toIso8601String())->toBe($finishedAt?->toIso8601String());
});

test('re-judging dispatches judge-only jobs for unscored results only', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->completed()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $unscored = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => 'failed']);
    BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 1)->scored(0.8, true)->create(['judge_status' => 'ok']);
    BenchmarkResult::factory()->forCase(BenchmarkRun::factory()->completed()->create(), $benchmarkCase, 0)->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => 'failed']);

    resolve(BenchmarkRunLauncher::class)->rejudge($benchmarkRun);

    Queue::assertPushed(RunBenchmarkCase::class, 1);
    Queue::assertPushed(RunBenchmarkCase::class, fn (RunBenchmarkCase $runBenchmarkCase): bool => $runBenchmarkCase->resultId === $unscored->id
        && $runBenchmarkCase->judgeOnly);
});

test('a launch without cases or candidates is refused and creates nothing', function (Closure $makeCases, array $candidates): void {
    expect(fn (): BenchmarkRun => resolve(BenchmarkRunLauncher::class)->launch(
        'Empty',
        $makeCases(),
        $candidates,
        ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'provider_default'],
        null,
        User::factory()->admin()->create(),
    ))->toThrow(InvalidArgumentException::class);

    expect(BenchmarkRun::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no cases' => [fn (): Collection => collect(), launcherCandidates()],
    'no candidates' => [fn (): Collection => collect([BenchmarkCase::factory()->create()]), []],
]);

test('re-judging twice queues one judge-only job per result', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->completed()->create();
    BenchmarkResult::factory()->forCase($benchmarkRun, BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(), 0)
        ->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => 'failed']);

    resolve(BenchmarkRunLauncher::class)->rejudge($benchmarkRun);
    resolve(BenchmarkRunLauncher::class)->rejudge($benchmarkRun);

    Queue::assertPushed(RunBenchmarkCase::class, 1);
});
