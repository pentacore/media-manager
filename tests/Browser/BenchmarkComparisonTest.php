<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');

    foreach (['gpt-a', 'gpt-b', 'gpt-judge', 'gpt-neutral'] as $model) {
        AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model, 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
    }

    AiTaskModel::factory()->task(AiTask::BenchmarkJudge)->selecting('openai', 'gpt-judge')->create();
});

/**
 * @param  list<array<string, mixed>>  $candidates
 */
function compareBrowserRun(string $name, array $candidates, string $judgeModel = 'gpt-judge'): BenchmarkRun
{
    return BenchmarkRun::factory()->completed()->withCandidates($candidates)->create([
        'name' => $name,
        'judge' => ['provider' => 'openai', 'model' => $judgeModel, 'reasoning' => null],
    ]);
}

function compareBrowserResult(BenchmarkRun $benchmarkRun, BenchmarkCase $benchmarkCase, int $candidateIndex, float $score, bool $passed, string $output): BenchmarkResult
{
    return BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, $candidateIndex)->scored($score, $passed)->create([
        'output' => $output,
        'latency_ms' => 500,
        'completion_tokens' => 50,
        'score_detail' => ['score_10' => (int) round($score * 10), 'rationale' => sprintf('Verdict on %s', $output), 'tool_log' => []],
    ]);
}

/**
 * Two runs of gpt-a and gpt-b over two title cases; gpt-a drops on "Nolan chat".
 *
 * @return array{0: BenchmarkRun, 1: BenchmarkRun}
 */
function compareBrowserRuns(string $afterJudge = 'gpt-judge'): array
{
    $nolan = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat', 'input' => ['prompt' => 'Which films did Nolan direct?']]);
    $villeneuve = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Villeneuve chat']);
    $candidates = [['provider' => 'openai', 'model' => 'gpt-a', 'reasoning' => null], ['provider' => 'openai', 'model' => 'gpt-b', 'reasoning' => null]];
    $before = compareBrowserRun('October baseline', $candidates);
    $after = compareBrowserRun('October rerun', $candidates, $afterJudge);

    compareBrowserResult($before, $nolan, 0, 0.9, true, 'Nolan Films');
    compareBrowserResult($after, $nolan, 0, 0.3, false, 'Some movies');
    compareBrowserResult($before, $nolan, 1, 0.8, true, 'Nolan list');
    compareBrowserResult($after, $nolan, 1, 0.8, true, 'Nolan list');

    foreach ([$before, $after] as $benchmarkRun) {
        compareBrowserResult($benchmarkRun, $villeneuve, 0, 0.7, true, 'Villeneuve films');
        compareBrowserResult($benchmarkRun, $villeneuve, 1, 0.7, true, 'Villeneuve films');
    }

    return [$before, $after];
}

function compareBrowserUrl(BenchmarkRun ...$runs): string
{
    return route('admin.benchmarks.compare.show', ['runs' => implode(',', array_map(static fn (BenchmarkRun $run): int => $run->id, $runs))], absolute: false);
}

test('the comparison shows each model across runs with deltas against the baseline', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $gptA = sprintf('%d.0', $before->id);

    visit(compareBrowserUrl($after, $before))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-compare-run="%d"]', $before->id), 'October baseline')
        ->assertVisible(sprintf('[data-compare-run="%d"] [data-compare-baseline]', $before->id))
        ->assertSeeIn('[data-compare-task="title"] [data-compare-task-counts]', '2 cases common to all runs · 0 excluded')
        ->assertSeeIn(sprintf('[data-compare-line="%s"]', $gptA), 'openai · gpt-a')
        ->assertSeeIn(sprintf('[data-compare-figure="%s:%d"]', $gptA, $before->id), '100%')
        ->assertSeeIn(sprintf('[data-compare-figure="%s:%d"]', $gptA, $after->id), '50%')
        ->assertSeeIn(sprintf('[data-compare-figure="%s:%d"] [data-compare-delta]', $gptA, $after->id), '▼50')
        ->click('[data-compare-metric="avg_score"]')
        ->assertSeeIn(sprintf('[data-compare-figure="%s:%d"]', $gptA, $before->id), '8.0')
        ->assertSeeIn(sprintf('[data-compare-figure="%s:%d"] [data-compare-delta]', $gptA, $after->id), '▼3.0')
        ->assertAttributeContains(sprintf('[data-compare-figure="%s:%d"] [data-compare-delta]', $gptA, $after->id), 'class', 'text-destructive');
});

test('an admin swaps the baseline, adds a run and removes it again', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $benchmarkRun = compareBrowserRun('November run', [['provider' => 'openai', 'model' => 'gpt-a', 'reasoning' => null]]);

    visit(compareBrowserUrl($before, $after))
        ->assertNoSmoke()
        ->click('[data-compare-swap]')
        ->assertVisible(sprintf('[data-compare-run="%d"] [data-compare-baseline]', $after->id))
        ->click('[data-compare-add-run]')
        ->click(sprintf('[data-compare-add-option="%d"]', $benchmarkRun->id))
        ->assertVisible(sprintf('[data-compare-run="%d"]', $benchmarkRun->id))
        ->assertMissing('[data-compare-swap]')
        ->assertSeeIn('[data-compare-task="title"] [data-compare-task-counts]', '0 cases common to all runs · 2 excluded')
        ->assertSeeIn('[data-compare-task="title"] [data-compare-task-empty]', 'These runs share no cases for this task')
        ->click(sprintf('[data-compare-run="%d"] [data-compare-remove-run]', $benchmarkRun->id))
        ->assertMissing(sprintf('[data-compare-run="%d"]', $benchmarkRun->id))
        ->assertVisible(sprintf('[data-compare-run="%d"] [data-compare-baseline]', $after->id));
});

test('two different models are paired by hand and unpaired again', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $benchmarkRun = compareBrowserRun('Old model', [['provider' => 'openai', 'model' => 'gpt-a', 'reasoning' => null]]);
    $new = compareBrowserRun('New model', [['provider' => 'openai', 'model' => 'gpt-b', 'reasoning' => null]]);
    compareBrowserResult($benchmarkRun, $benchmarkCase, 0, 0.9, true, 'Old answer');
    compareBrowserResult($new, $benchmarkCase, 0, 0.6, false, 'New answer');
    $oldLine = sprintf('%d.0', $benchmarkRun->id);
    $newLine = sprintf('%d.0', $new->id);

    visit(compareBrowserUrl($benchmarkRun, $new))
        ->assertNoSmoke()
        ->assertVisible(sprintf('[data-compare-line="%s"]', $newLine))
        ->click(sprintf('[data-compare-line="%s"] [data-compare-pair]', $oldLine))
        ->click(sprintf('[data-compare-pair-option="%s"]', $newLine))
        ->assertMissing(sprintf('[data-compare-line="%s"]', $newLine))
        ->assertSeeIn(sprintf('[data-compare-line="%s"]', $oldLine), 'openai · gpt-a / openai · gpt-b')
        ->assertSeeIn(sprintf('[data-compare-figure="%s:%d"] [data-compare-delta]', $oldLine, $new->id), '▼100')
        ->click(sprintf('[data-compare-line="%s"] [data-compare-unpair]', $oldLine))
        ->assertVisible(sprintf('[data-compare-line="%s"]', $newLine));
});

test('the case grid shows the changed rows and can show every case', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $benchmarkCase = BenchmarkCase::query()->where('name', 'Nolan chat')->sole();
    $villeneuve = BenchmarkCase::query()->where('name', 'Villeneuve chat')->sole();
    $gptA = sprintf('%d.0', $before->id);

    visit(compareBrowserUrl($before, $after))
        ->assertNoSmoke()
        ->assertSeeIn('[data-compare-case-grid="title"] [data-compare-changed-count]', '1 of 4 rows changed')
        ->assertVisible(sprintf('[data-compare-case-row="%d:%s"]', $benchmarkCase->id, $gptA))
        ->assertMissing(sprintf('[data-compare-case-row="%d:%s"]', $villeneuve->id, $gptA))
        ->click('[data-compare-case-grid="title"] [data-compare-show-all]')
        ->assertVisible(sprintf('[data-compare-case-row="%d:%s"]', $villeneuve->id, $gptA));
});

test('a cell opens the result drawer and vs baseline shows both outputs', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $benchmarkResult = BenchmarkResult::query()->where('benchmark_run_id', $before->id)->where('output', 'Nolan Films')->sole();
    $afterResult = BenchmarkResult::query()->where('benchmark_run_id', $after->id)->where('output', 'Some movies')->sole();

    visit(compareBrowserUrl($before, $after))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-compare-cell="%d"]', $afterResult->id), '✗ 3.0')
        ->click(sprintf('[data-compare-cell="%d"]', $afterResult->id))
        ->assertVisible('[data-benchmark-drawer]')
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"] [data-benchmark-drawer-output]', 'Some movies')
        ->assertSeeIn('[data-benchmark-drawer-prompt]', 'Which films did Nolan direct?')
        ->keys('[data-benchmark-drawer]', 'Escape')
        ->click(sprintf('[data-compare-vs-baseline="%d:%d"]', $benchmarkResult->id, $afterResult->id))
        ->assertVisible('[data-compare-outputs]')
        ->assertSeeIn('[data-compare-output-side="baseline"] [data-compare-output-text]', 'Nolan Films')
        ->assertSeeIn('[data-compare-output-side="baseline"]', 'Verdict on Nolan Films')
        ->assertSeeIn('[data-compare-output-side="other"] [data-compare-output-text]', 'Some movies')
        ->assertSeeIn('[data-compare-output-side="other"]', 'October rerun');
});

test('runs judged by different judges are flagged', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns(afterJudge: 'gpt-b');

    visit(compareBrowserUrl($before, $after))
        ->assertNoSmoke()
        ->assertSeeIn('[data-compare-judges-differ]', 'openai · gpt-judge')
        ->assertSeeIn('[data-compare-judges-differ]', 'openai · gpt-b');
});

test('an admin re-judges both runs with a neutral judge and switches to its scores', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    BenchmarkJudgeAgent::fake(fn (): array => ['score' => 6, 'rationale' => 'Neutral take']);
    $gptA = sprintf('%d.0', $before->id);
    $key = 'openai|gpt-neutral|provider_default';

    visit(compareBrowserUrl($before, $after))
        ->assertNoSmoke()
        ->assertMissing(sprintf('[data-compare-score-source="%s"]', $key))
        ->click('[data-compare-neutral-open]')
        ->assertVisible('[data-compare-neutral-dialog]')
        ->click('[data-compare-neutral-dialog] [data-benchmark-model-select] button')
        ->click('[role="option"][aria-label="gpt-neutral"]')
        ->click('[data-compare-neutral-estimate]')
        ->assertSeeIn('[data-compare-neutral-estimate-total]', '8 results')
        ->fill('[data-compare-neutral-cap]', '1')
        ->click('[data-compare-neutral-start]')
        ->assertSee('Neutral re-judge started.')
        ->assertSeeIn('[data-compare-rejudge-status]', 'Completed')
        ->click(sprintf('[data-compare-score-source="%s"]', $key))
        ->click('[data-compare-metric="avg_score"]')
        ->assertSeeIn(sprintf('[data-compare-figure="%s:%d"]', $gptA, $before->id), '6.0');

    expect(BenchmarkNeutralJudgement::query()->count())->toBe(8);
});

test('a running re-judge shows its progress and can be cancelled', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $judge = ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null];
    $rejudge = BenchmarkNeutralRejudge::factory()->running()->forRuns([$before->id, $after->id], $judge)->create();

    foreach (BenchmarkResult::query()->where('benchmark_run_id', $before->id)->get() as $benchmarkResult) {
        BenchmarkNeutralJudgement::factory()->forResult($benchmarkResult, $judge)->queued()->create(['benchmark_neutral_rejudge_id' => $rejudge->id]);
    }

    visit(compareBrowserUrl($before, $after))
        ->assertNoSmoke()
        ->assertSeeIn('[data-compare-rejudge-status]', '0 / 4')
        ->assertAttribute('[data-compare-neutral-open]', 'disabled', '')
        ->click('[data-compare-rejudge-cancel]')
        ->click('[data-confirm-accept]')
        ->assertSee('Neutral re-judge cancelled.')
        ->assertSeeIn('[data-compare-rejudge-status]', 'Cancelled');
});

test('ticking two runs on the runs list opens their comparison', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $running = BenchmarkRun::factory()->running()->create(['name' => 'Still going']);

    visit(route('admin.benchmarks.runs.index', absolute: false))
        ->assertNoSmoke()
        ->assertAttribute(sprintf('[data-benchmark-run-select="%d"]', $running->id), 'disabled', '')
        ->click(sprintf('[data-benchmark-run-select="%d"]', $before->id))
        ->assertSeeIn('[data-benchmark-compare-bar]', '1 selected')
        ->assertAttribute('[data-benchmark-compare-selected]', 'disabled', '')
        ->click(sprintf('[data-benchmark-run-select="%d"]', $after->id))
        ->click('[data-benchmark-compare-selected]')
        ->assertVisible('[data-compare-page]')
        ->assertVisible(sprintf('[data-compare-run="%d"] [data-compare-baseline]', $before->id));
});

test('a run page compares with the previous scheduled run', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $benchmarkSchedule = BenchmarkSchedule::factory()->create();
    $before->update(['benchmark_schedule_id' => $benchmarkSchedule->id]);
    $after->update(['benchmark_schedule_id' => $benchmarkSchedule->id]);

    visit(route('admin.benchmarks.runs.show', $after, absolute: false))
        ->assertNoSmoke()
        ->click('[data-benchmark-compare-with]')
        ->assertSeeIn(sprintf('[data-benchmark-compare-option="%d"]', $before->id), 'Previous scheduled run')
        ->click(sprintf('[data-benchmark-compare-option="%d"]', $before->id))
        ->assertVisible('[data-compare-page]')
        ->assertVisible(sprintf('[data-compare-run="%d"] [data-compare-baseline]', $before->id));
});

test('the regression banner opens the comparison with its baseline', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();
    $after->update([
        'baseline_run_id' => $before->id,
        'regressions' => [[
            'candidate' => ['provider' => 'openai', 'model' => 'gpt-a', 'reasoning' => null],
            'paired_cases' => 2,
            'metrics' => [['metric' => 'pass_rate', 'before' => 1.0, 'after' => 0.5]],
        ]],
    ]);

    visit(route('admin.benchmarks.runs.show', $after, absolute: false))
        ->assertNoSmoke()
        ->click('[data-benchmark-compare-baseline]')
        ->assertVisible('[data-compare-page]')
        ->assertVisible(sprintf('[data-compare-run="%d"] [data-compare-baseline]', $before->id));
});

test('the comparison fits a phone screen', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$before, $after] = compareBrowserRuns();

    visit(compareBrowserUrl($before, $after))->on()->mobile()
        ->assertNoSmoke()
        ->assertScript(phoneOverflowScript('[data-compare-page]'), '');
});
