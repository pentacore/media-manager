<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkMatrix;

test('latency percentiles use the nearest rank over ok results', function (): void {
    $run = BenchmarkRun::factory()->completed()->create();
    $case = BenchmarkCase::factory()->create();

    foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000] as $latency) {
        BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->create(), 0)->scored(0.5, true)->create(['latency_ms' => $latency]);
    }

    BenchmarkResult::factory()->forCase($run, $case, 0)->failed('boom')->create(['latency_ms' => 99999]);

    $candidate = BenchmarkMatrix::for($run)[0]['candidates'][0];

    expect($candidate['latency_p50'])->toBe(500)
        ->and($candidate['latency_p95'])->toBe(1000)
        ->and($candidate['results'])->toBe(11)
        ->and($candidate['failed'])->toBe(1);
});

test('a candidate without scored results has null figures and never wins', function (): void {
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'b', 'reasoning' => 'low'],
    ])->create();
    $case = BenchmarkCase::factory()->create();
    BenchmarkResult::factory()->forCase($run, $case, 0)->failed('boom')->create();
    BenchmarkResult::factory()->forCase($run, $case, 1)->scored(0.4, false)->create(['latency_ms' => 10, 'cost_usd' => 0.5, 'judge_cost_usd' => 0.25]);

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['candidates'][0]['avg_score'])->toBeNull()
        ->and($matrix['candidates'][0]['pass_rate'])->toBeNull()
        ->and($matrix['candidates'][0]['latency_p50'])->toBeNull()
        ->and($matrix['candidates'][0]['cost_per_case'])->toBeNull()
        ->and($matrix['candidates'][0]['reasoning_label'])->toBe('Provider default')
        ->and($matrix['candidates'][1]['cost_per_case'])->toBe(0.75)
        ->and($matrix['best'])->toBe(['avg_score' => 1, 'pass_rate' => 1, 'cost_per_case' => 1, 'latency_p50' => 1]);
});

test('each task present in the run gets its own matrix', function (): void {
    $run = BenchmarkRun::factory()->completed()->create(['judge' => null]);
    BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->forTask(AiTask::Title)->create(), 0)->create(['status' => BenchmarkResultStatus::Pending]);
    BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->create(), 0)->create(['status' => BenchmarkResultStatus::Pending]);

    $matrix = BenchmarkMatrix::for($run);

    expect(array_column($matrix, 'task'))->toBe(['title', 'decision'])
        ->and($matrix[0]['candidates'][0]['self_judged'])->toBeFalse();
});

test('a candidate with only pending results has no cost per case', function (): void {
    $run = BenchmarkRun::factory()->running()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'b', 'reasoning' => null],
    ])->create();
    $case = BenchmarkCase::factory()->create();
    BenchmarkResult::factory()->forCase($run, $case, 0)->create(['status' => BenchmarkResultStatus::Pending]);
    BenchmarkResult::factory()->forCase($run, $case, 1)->scored(1.0, true)->create(['cost_usd' => 0.2]);

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['candidates'][0]['cost_per_case'])->toBeNull()
        ->and($matrix['candidates'][1]['cost_per_case'])->toBe(0.2)
        ->and($matrix['best']['cost_per_case'])->toBe(1);
});

test('each cell carries its judge status', function (): void {
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'b', 'reasoning' => null],
    ])->create();
    $case = BenchmarkCase::factory()->create();
    BenchmarkResult::factory()->forCase($run, $case, 0)->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => BenchmarkJudgeStatus::Failed]);
    BenchmarkResult::factory()->forCase($run, $case, 1)->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => BenchmarkJudgeStatus::Pending]);

    $cells = BenchmarkMatrix::for($run)[0]['cases'][0]['cells'];

    expect($cells[0]['judge_status'])->toBe('failed')
        ->and($cells[1]['judge_status'])->toBe('pending');
});

test('each cell flags a stalled re-judge and a running result whose claim went stale', function (): void {
    $run = BenchmarkRun::factory()->cancelled()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'b', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'c', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'd', 'reasoning' => null],
    ])->create();
    $case = BenchmarkCase::factory()->create();
    $staleJudge = BenchmarkResult::factory()->forCase($run, $case, 0)->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => BenchmarkJudgeStatus::Pending]);
    BenchmarkResult::factory()->forCase($run, $case, 1)->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => BenchmarkJudgeStatus::Queued]);
    $staleRun = BenchmarkResult::factory()->forCase($run, $case, 2)->running()->create();
    BenchmarkResult::factory()->forCase($run, $case, 3)->running()->create();
    BenchmarkResult::query()->whereKey([$staleJudge->id, $staleRun->id])->toBase()->update(['updated_at' => now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 1)]);

    $cells = BenchmarkMatrix::for($run)[0]['cases'][0]['cells'];

    expect(array_column($cells, 'judge_stale'))->toBe([true, false, false, false])
        ->and(array_column($cells, 'stale'))->toBe([false, false, true, false]);
});

test('only candidates scored on every scored case can be best by score or pass rate', function (): void {
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'full', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'partial', 'reasoning' => null],
    ])->create();

    foreach (range(1, 4) as $index) {
        $case = BenchmarkCase::factory()->create();
        BenchmarkResult::factory()->forCase($run, $case, 0)->scored(0.6, $index > 2)->create();

        if ($index === 4) {
            BenchmarkResult::factory()->forCase($run, $case, 1)->failed('boom')->create();

            continue;
        }

        BenchmarkResult::factory()->forCase($run, $case, 1)->scored(0.9, true)->create();
    }

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['max_scored'])->toBe(4)
        ->and($matrix['candidates'][0]['scored'])->toBe(4)
        ->and($matrix['candidates'][1]['scored'])->toBe(3)
        ->and($matrix['candidates'][1]['avg_score'])->toBe(0.9)
        ->and($matrix['best']['avg_score'])->toBe(0)
        ->and($matrix['best']['pass_rate'])->toBe(0);
});

test('a task without any scored result has no best score', function (): void {
    $run = BenchmarkRun::factory()->completed()->create();
    BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->create(), 0)->failed('boom')->create();

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['max_scored'])->toBe(0)
        ->and($matrix['candidates'][0]['scored'])->toBe(0)
        ->and($matrix['best']['avg_score'])->toBeNull()
        ->and($matrix['best']['pass_rate'])->toBeNull();
});

test('a verdict task counts missed and false alarm verdicts per candidate', function (): void {
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'b', 'reasoning' => null],
    ])->create();
    $stopIt = BenchmarkCase::factory()->expectingVerdict(false)->create();
    $letItRun = BenchmarkCase::factory()->expectingVerdict(true)->create();
    $alsoStop = BenchmarkCase::factory()->expectingVerdict(false)->create();
    BenchmarkResult::factory()->forCase($run, $stopIt, 0)->scored(0.0, false)->create(['score_detail' => ['error_kind' => 'missed']]);
    BenchmarkResult::factory()->forCase($run, $letItRun, 0)->scored(0.0, false)->create(['score_detail' => ['error_kind' => 'false_alarm']]);
    BenchmarkResult::factory()->forCase($run, $alsoStop, 0)->scored(1.0, true)->create(['score_detail' => ['error_kind' => null]]);
    BenchmarkResult::factory()->forCase($run, $stopIt, 1)->scored(1.0, true)->create(['score_detail' => ['error_kind' => null]]);
    BenchmarkResult::factory()->forCase($run, $letItRun, 1)->scored(1.0, true)->create(['score_detail' => ['error_kind' => null]]);
    BenchmarkResult::factory()->forCase($run, $alsoStop, 1)->failed('boom')->create(['score_detail' => ['error_kind' => 'missed']]);

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['task'])->toBe('decision_verifier')
        ->and($matrix['verdict'])->toBeTrue()
        ->and($matrix['candidates'][0]['missed'])->toBe(1)
        ->and($matrix['candidates'][0]['false_alarms'])->toBe(1)
        ->and($matrix['candidates'][1]['missed'])->toBe(0)
        ->and($matrix['candidates'][1]['false_alarms'])->toBe(0);
});

test('a verdict task never marks a candidate self-judged even when it is the judge', function (): void {
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
    ])->create(['judge' => ['provider' => 'openai', 'model' => 'a', 'reasoning' => null]]);
    $case = BenchmarkCase::factory()->expectingVerdict(false)->create();
    BenchmarkResult::factory()->forCase($run, $case, 0)->scored(1.0, true)->create(['score_detail' => ['error_kind' => null]]);

    expect(BenchmarkMatrix::for($run)[0]['candidates'][0]['self_judged'])->toBeFalse();
});

test('a task scored without verdicts has no missed or false alarm counts', function (): void {
    $run = BenchmarkRun::factory()->completed()->create();
    BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(), 0)->scored(0.8, true)->create();

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['verdict'])->toBeFalse()
        ->and($matrix['candidates'][0]['missed'])->toBeNull()
        ->and($matrix['candidates'][0]['false_alarms'])->toBeNull();
});
