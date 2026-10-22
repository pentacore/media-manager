<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkComparison;
use App\Services\Benchmark\BenchmarkComparisonScope;

/**
 * @return array{provider: string, model: string, reasoning: string|null}
 */
function compareSelection(string $model, ?string $reasoning = null): array
{
    return ['provider' => 'openai', 'model' => $model, 'reasoning' => $reasoning];
}

/**
 * @param  list<array<string, mixed>>  $candidates
 */
function compareRun(array $candidates, string $judgeModel = 'gpt-judge'): BenchmarkRun
{
    return BenchmarkRun::factory()->completed()->withCandidates($candidates)->create(['judge' => compareSelection($judgeModel)]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function compareResult(BenchmarkRun $benchmarkRun, BenchmarkCase $benchmarkCase, int $candidateIndex, ?float $score, ?bool $passed, array $attributes = []): BenchmarkResult
{
    return BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, $candidateIndex)->create([
        'status' => BenchmarkResultStatus::Ok,
        'score' => $score,
        'passed' => $passed,
        ...$attributes,
    ]);
}

function compareTitleCase(string $name): BenchmarkCase
{
    return BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => $name]);
}

/**
 * @return array<string, mixed>
 */
function compareBuild(array $runIds, ?int $baseline = null, ?string $pairs = null, ?string $scores = null): array
{
    return resolve(BenchmarkComparison::class)->build($runIds, $baseline, BenchmarkComparison::parsePairs($pairs), $scores);
}

test('only cases every run has are compared and the rest are counted as excluded', function (): void {
    [$shared, $onlyA, $onlyB] = [compareTitleCase('Shared'), compareTitleCase('Only A'), compareTitleCase('Only B')];
    $benchmarkRun = compareRun([compareSelection('gpt-a')]);
    $runB = compareRun([compareSelection('gpt-a')]);
    compareResult($benchmarkRun, $shared, 0, 0.8, true);
    compareResult($benchmarkRun, $onlyA, 0, 0.8, true);
    compareResult($runB, $shared, 0, 0.6, false);
    compareResult($runB, $onlyB, 0, 0.6, false);
    BenchmarkResult::factory()->create(['benchmark_run_id' => $benchmarkRun->id, 'benchmark_case_id' => null, 'case_task' => 'title', 'status' => BenchmarkResultStatus::Ok]);

    expect(BenchmarkComparisonScope::commonCases([$benchmarkRun->id, $runB->id]))->toBe(['title' => ['common' => [$shared->id], 'excluded' => 2]]);

    $comparison = compareBuild([$benchmarkRun->id, $runB->id]);

    expect($comparison['tasks'][0])->toMatchArray(['task' => 'title', 'task_label' => 'Conversation titles', 'common_cases' => 1, 'excluded_cases' => 2])
        ->and($comparison['tasks'][0]['rows'][0]['figures'][0]['results'])->toBe(1)
        ->and($comparison['tasks'][0]['cases'])->toHaveCount(1);
});

test('identical selections share a line and the rest get their own', function (): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-a'), compareSelection('gpt-b', 'high')]);
    $runB = compareRun([compareSelection('gpt-c'), compareSelection('gpt-a')]);
    compareResult($benchmarkRun, $benchmarkCase, 0, 0.8, true);
    compareResult($benchmarkRun, $benchmarkCase, 1, 0.7, true);
    compareResult($runB, $benchmarkCase, 0, 0.5, false);
    compareResult($runB, $benchmarkCase, 1, 0.6, false);

    $comparison = compareBuild([$benchmarkRun->id, $runB->id]);

    expect(array_column($comparison['lines'], 'label'))->toBe(['openai · gpt-a', 'openai · gpt-b · High', 'openai · gpt-c'])
        ->and($comparison['lines'][0]['members'])->toBe([['run_id' => $benchmarkRun->id, 'candidate_index' => 0], ['run_id' => $runB->id, 'candidate_index' => 1]])
        ->and($comparison['lines'][0]['key'])->toBe(sprintf('%d.0', $benchmarkRun->id))
        ->and($comparison['tasks'][0]['rows'][1]['figures'][1])->toBeNull();
});

test('the oldest run is the baseline unless another is picked, and deltas are against it', function (): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-a')]);
    $runB = compareRun([compareSelection('gpt-a')]);
    compareResult($benchmarkRun, $benchmarkCase, 0, 0.9, true, ['latency_ms' => 400]);
    compareResult($runB, $benchmarkCase, 0, 0.4, false, ['latency_ms' => 900]);

    $default = compareBuild([$runB->id, $benchmarkRun->id]);
    $picked = compareBuild([$benchmarkRun->id, $runB->id], baseline: $runB->id);

    expect(array_column($default['runs'], 'id'))->toBe([$benchmarkRun->id, $runB->id])
        ->and($default['runs'][0]['is_baseline'])->toBeTrue()
        ->and($default['tasks'][0]['rows'][0]['deltas'][0])->toBeNull()
        ->and($default['tasks'][0]['rows'][0]['deltas'][1])->toBe(['avg_score' => -0.5, 'pass_rate' => -1.0, 'cost_per_case' => 0.0, 'latency_p50' => 500.0, 'error_rate' => 0.0])
        ->and(array_column($picked['runs'], 'id'))->toBe([$runB->id, $benchmarkRun->id])
        ->and($picked['tasks'][0]['rows'][0]['deltas'][1]['pass_rate'])->toBe(1.0);
});

test('a line missing from the baseline has no deltas and its rows are changed', function (): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-a')]);
    $runB = compareRun([compareSelection('gpt-a'), compareSelection('gpt-new')]);
    compareResult($benchmarkRun, $benchmarkCase, 0, 0.8, true);
    compareResult($runB, $benchmarkCase, 0, 0.8, true);
    compareResult($runB, $benchmarkCase, 1, 0.9, true);

    $task = compareBuild([$benchmarkRun->id, $runB->id])['tasks'][0];
    $newRow = collect($task['rows'])->firstWhere('line_key', sprintf('%d.1', $runB->id));
    $newCase = collect($task['cases'])->firstWhere('line_key', sprintf('%d.1', $runB->id));
    $sameCase = collect($task['cases'])->firstWhere('line_key', sprintf('%d.0', $benchmarkRun->id));

    expect($newRow['figures'][0])->toBeNull()
        ->and($newRow['deltas'])->toBe([null, null])
        ->and($newCase['changed'])->toBeTrue()
        ->and($sameCase['changed'])->toBeFalse();
});

test('a manual pair merges two lines and is echoed back', function (): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-old')]);
    $runB = compareRun([compareSelection('gpt-new')]);
    compareResult($benchmarkRun, $benchmarkCase, 0, 0.8, true);
    compareResult($runB, $benchmarkCase, 0, 0.6, false);
    $pair = sprintf('%d.0~%d.0', $benchmarkRun->id, $runB->id);

    $comparison = compareBuild([$benchmarkRun->id, $runB->id], pairs: $pair);

    expect($comparison['lines'])->toHaveCount(1)
        ->and($comparison['lines'][0])->toMatchArray(['label' => 'openai · gpt-old / openai · gpt-new', 'paired' => true])
        ->and($comparison['pairs'])->toBe([$pair])
        ->and($comparison['tasks'][0]['rows'][0]['deltas'][1]['avg_score'])->toBe(-0.2);
});

test('invalid pairs are ignored', function (string $pairs): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-a'), compareSelection('gpt-b')]);
    $runB = compareRun([compareSelection('gpt-a'), compareSelection('gpt-b')]);
    foreach ([$benchmarkRun, $runB] as $run) {
        compareResult($run, $benchmarkCase, 0, 0.8, true);
        compareResult($run, $benchmarkCase, 1, 0.8, true);
    }

    $pairs = strtr($pairs, ['A' => (string) $benchmarkRun->id, 'B' => (string) $runB->id]);
    $comparison = compareBuild([$benchmarkRun->id, $runB->id], pairs: $pairs);

    expect($comparison['lines'])->toHaveCount(2)
        ->and($comparison['pairs'])->toBe([]);
})->with([
    'index past the end' => ['A.0~B.7'],
    'run not compared' => ['A.0~999999.0'],
    'two candidates of one run' => ['A.0~B.1'],
    'same line' => ['A.0~B.0'],
    'garbage' => ['nonsense,1~2,A0B1'],
]);

test('error rate counts failures among finished cases', function (): void {
    [$first, $second] = [compareTitleCase('One'), compareTitleCase('Two')];
    $benchmarkRun = compareRun([compareSelection('gpt-a')]);
    $runB = compareRun([compareSelection('gpt-a')]);
    compareResult($benchmarkRun, $first, 0, 0.8, true);
    compareResult($benchmarkRun, $second, 0, 0.8, true);
    compareResult($runB, $first, 0, 0.8, true);
    compareResult($runB, $second, 0, null, null, ['status' => BenchmarkResultStatus::Failed, 'error' => 'boom']);

    $row = compareBuild([$benchmarkRun->id, $runB->id])['tasks'][0]['rows'][0];

    expect($row['figures'][0]['error_rate'])->toBe(0.0)
        ->and($row['figures'][1]['error_rate'])->toBe(0.5)
        ->and($row['deltas'][1]['error_rate'])->toBe(0.5);
});

test('a row changes on status, verdict or a score move of at least one judge point', function (?float $before, ?bool $passedBefore, ?float $after, ?bool $passedAfter, bool $changed): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-a')]);
    $runB = compareRun([compareSelection('gpt-a')]);
    compareResult($benchmarkRun, $benchmarkCase, 0, $before, $passedBefore);
    compareResult($runB, $benchmarkCase, 0, $after, $passedAfter);

    expect(compareBuild([$benchmarkRun->id, $runB->id])['tasks'][0]['cases'][0]['changed'])->toBe($changed);
})->with([
    'same' => [0.8, true, 0.8, true, false],
    'half a point' => [0.8, true, 0.85, true, false],
    'one point' => [0.8, true, 0.7, true, true],
    'verdict flips' => [0.7, true, 0.7, false, true],
    'score lost' => [0.8, true, null, null, true],
]);

test('neutral scores replace judged results only and coverage counts the judged ones', function (): void {
    $benchmarkCase = compareTitleCase('Judged');
    $decision = BenchmarkCase::factory()->create(['name' => 'Decision']);
    $benchmarkRun = compareRun([compareSelection('gpt-a')], 'gpt-judge-a');
    $runB = compareRun([compareSelection('gpt-a')], 'gpt-judge-b');
    $benchmarkResult = compareResult($benchmarkRun, $benchmarkCase, 0, 0.9, true);
    $judgedB = compareResult($runB, $benchmarkCase, 0, 0.3, false);
    compareResult($benchmarkRun, $decision, 0, 1.0, true, ['case_task' => 'decision']);
    compareResult($runB, $decision, 0, 0.5, false, ['case_task' => 'decision']);
    $neutralJudge = compareSelection('gpt-neutral');
    BenchmarkNeutralJudgement::factory()->forResult($benchmarkResult, $neutralJudge)->scored(0.6, false)->create();
    $key = BenchmarkNeutralJudgement::keyFor($neutralJudge);

    $original = compareBuild([$benchmarkRun->id, $runB->id]);
    $neutral = compareBuild([$benchmarkRun->id, $runB->id], scores: $key);
    $unknown = compareBuild([$benchmarkRun->id, $runB->id], scores: 'nobody|nothing|none');
    $titleTask = fn (array $comparison): array => collect($comparison['tasks'])->firstWhere('task', 'title');
    $decisionTask = fn (array $comparison): array => collect($comparison['tasks'])->firstWhere('task', 'decision');

    expect($original['judges_differ'])->toBeTrue()
        ->and($original['score_source'])->toBe(['current' => 'original', 'options' => [['key' => $key, 'label' => 'openai · gpt-neutral', 'coverage' => 0.5]]])
        ->and($neutral['score_source']['current'])->toBe($key)
        ->and($titleTask($neutral)['rows'][0]['figures'][0]['avg_score'])->toBe(0.6)
        ->and($titleTask($neutral)['rows'][0]['figures'][1]['avg_score'])->toBeNull()
        ->and($titleTask($neutral)['cases'][0]['cells'][1]['score'])->toBeNull()
        ->and($decisionTask($neutral)['rows'][0]['figures'][1]['avg_score'])->toBe(0.5)
        ->and($unknown['score_source']['current'])->toBe('original')
        ->and($titleTask($unknown)['rows'][0]['figures'][0]['avg_score'])->toBe(0.9)
        ->and($judgedB->fresh()->score)->toBe(0.3);
});

test('a candidate is self-judged against the judge whose scores are shown', function (): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-judge'), compareSelection('gpt-neutral')]);
    $runB = compareRun([compareSelection('gpt-judge'), compareSelection('gpt-neutral')]);
    foreach ([$benchmarkRun, $runB] as $run) {
        foreach ([0, 1] as $index) {
            $result = compareResult($run, $benchmarkCase, $index, 0.8, true);
            BenchmarkNeutralJudgement::factory()->forResult($result, compareSelection('gpt-neutral'))->scored(0.7, true)->create();
        }
    }

    $original = compareBuild([$benchmarkRun->id, $runB->id])['tasks'][0]['rows'];
    $neutral = compareBuild([$benchmarkRun->id, $runB->id], scores: BenchmarkNeutralJudgement::keyFor(compareSelection('gpt-neutral')))['tasks'][0]['rows'];

    expect($original[0]['figures'][0]['self_judged'])->toBeTrue()
        ->and($original[1]['figures'][0]['self_judged'])->toBeFalse()
        ->and($neutral[0]['figures'][0]['self_judged'])->toBeFalse()
        ->and($neutral[1]['figures'][0]['self_judged'])->toBeTrue();
});

test('a task with no common cases has no rows', function (): void {
    $benchmarkRun = compareRun([compareSelection('gpt-a')]);
    $runB = compareRun([compareSelection('gpt-a')]);
    compareResult($benchmarkRun, compareTitleCase('Only A'), 0, 0.8, true);
    compareResult($runB, compareTitleCase('Only B'), 0, 0.8, true);

    $task = compareBuild([$benchmarkRun->id, $runB->id])['tasks'][0];

    expect($task)->toMatchArray(['common_cases' => 0, 'excluded_cases' => 2, 'rows' => [], 'cases' => []]);
});

test('outputs show both sides with the rationale of the score source', function (): void {
    $benchmarkCase = compareTitleCase('Shared');
    $benchmarkRun = compareRun([compareSelection('gpt-a')]);
    $runB = compareRun([compareSelection('gpt-a')]);
    $benchmarkResult = compareResult($benchmarkRun, $benchmarkCase, 0, 0.8, true, ['output' => 'Old title', 'score_detail' => ['rationale' => 'Original verdict']]);
    $after = compareResult($runB, $benchmarkCase, 0, 0.4, false, ['output' => 'New title', 'score_detail' => ['rationale' => 'Worse']]);
    BenchmarkNeutralJudgement::factory()->forResult($benchmarkResult, compareSelection('gpt-neutral'))->scored(0.6, false)->create(['detail' => ['score_10' => 6, 'rationale' => 'Neutral take']]);
    $benchmarkComparison = resolve(BenchmarkComparison::class);

    $original = $benchmarkComparison->outputs([$benchmarkRun->id, $runB->id], [$benchmarkResult->id, $after->id], 'original');
    $neutral = $benchmarkComparison->outputs([$benchmarkRun->id, $runB->id], [$benchmarkResult->id, $after->id], BenchmarkNeutralJudgement::keyFor(compareSelection('gpt-neutral')));

    expect($original[0])->toMatchArray(['result_id' => $benchmarkResult->id, 'output' => 'Old title', 'score' => 0.8, 'rationale' => 'Original verdict', 'judge_label' => 'openai · gpt-judge', 'candidate_label' => 'openai · gpt-a'])
        ->and($original[1]['output'])->toBe('New title')
        ->and($neutral[0])->toMatchArray(['score' => 0.6, 'passed' => false, 'rationale' => 'Neutral take', 'judge_label' => 'openai · gpt-neutral'])
        ->and($neutral[1])->toMatchArray(['score' => null, 'rationale' => null])
        ->and($benchmarkComparison->outputs([$benchmarkRun->id], [$after->id], 'original'))->toBe([]);
});
