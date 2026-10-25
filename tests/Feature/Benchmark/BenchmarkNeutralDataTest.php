<?php

declare(strict_types=1);

use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkNeutralRejudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use Illuminate\Database\UniqueConstraintViolationException;

test('a judge key joins provider, model and reasoning with the provider default filled in', function (): void {
    expect(BenchmarkNeutralJudgement::keyFor(['provider' => 'openrouter', 'model' => 'google/gemini', 'reasoning' => null]))->toBe('openrouter|google/gemini|provider_default')
        ->and(BenchmarkNeutralJudgement::keyFor(['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'high']))->toBe('openai|gpt-judge|high');
});

test('a result has at most one neutral judgement per judge', function (): void {
    $result = BenchmarkResult::factory()->scored(0.8, true)->create();
    $judge = ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null];
    BenchmarkNeutralJudgement::factory()->forResult($result, $judge)->create();

    expect(fn () => BenchmarkNeutralJudgement::factory()->forResult($result, $judge)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('deleting a run removes its neutral judgements but keeps the re-judge record', function (): void {
    $result = BenchmarkResult::factory()->scored(0.8, true)->create();
    $rejudge = BenchmarkNeutralRejudge::factory()->forRuns([$result->benchmark_run_id], ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null])->create();
    BenchmarkNeutralJudgement::factory()->forResult($result, $rejudge->judge)->create(['benchmark_neutral_rejudge_id' => $rejudge->id]);

    $result->run->delete();

    expect(BenchmarkNeutralJudgement::query()->count())->toBe(0)
        ->and($rejudge->fresh())->not->toBeNull();
});

test('only finished runs with results are comparable', function (): void {
    $completed = BenchmarkRun::factory()->completed()->create();
    $capped = BenchmarkRun::factory()->stoppedCostCap()->create();
    $running = BenchmarkRun::factory()->running()->create();
    $cancelledEmpty = BenchmarkRun::factory()->cancelled()->create();
    $cancelledWithResult = BenchmarkRun::factory()->cancelled()->create();
    BenchmarkResult::factory()->create(['benchmark_run_id' => $cancelledWithResult->id, 'status' => BenchmarkResultStatus::Failed]);
    BenchmarkResult::factory()->create(['benchmark_run_id' => $cancelledEmpty->id, 'status' => BenchmarkResultStatus::Skipped]);

    expect(BenchmarkRun::query()->comparable()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$completed->id, $capped->id, $cancelledWithResult->id])->sort()->values()->all())
        ->and($running->isComparable())->toBeFalse()
        ->and($cancelledEmpty->isComparable())->toBeFalse()
        ->and($cancelledWithResult->isComparable())->toBeTrue();
});

test('the stale scope finds judgements stuck queued or pending past the claim window', function (): void {
    $fresh = BenchmarkNeutralJudgement::factory()->queued()->create();
    $stale = BenchmarkNeutralJudgement::factory()->queued()->create();
    $done = BenchmarkNeutralJudgement::factory()->create();
    BenchmarkNeutralJudgement::query()->whereKey([$stale->id, $done->id])->toBase()->update(['updated_at' => now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 1)]);

    expect(BenchmarkNeutralJudgement::query()->stale()->pluck('id')->all())->toBe([$stale->id])
        ->and($fresh->status)->toBe(BenchmarkJudgeStatus::Queued)
        ->and(BenchmarkNeutralRejudgeStatus::Running->isTerminal())->toBeFalse()
        ->and(BenchmarkNeutralRejudgeStatus::Cancelled->isTerminal())->toBeTrue();
});
