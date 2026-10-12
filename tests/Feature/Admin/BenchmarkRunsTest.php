<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Jobs\RunBenchmarkCase;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;
use App\Services\Benchmark\BenchmarkScorer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.anthropic.key', 'sk-ant-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-candidate', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-candidate', 'input_per_mtok' => 3, 'output_per_mtok' => 15]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
});

/**
 * @param  list<BenchmarkCase>  $cases
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function benchmarkRunPayload(array $cases, array $overrides = []): array
{
    return [
        'name' => 'Spring bake-off',
        'case_ids' => array_map(fn (BenchmarkCase $benchmarkCase): int => $benchmarkCase->id, $cases),
        'candidates' => [
            ['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'provider_default'],
            ['provider' => 'anthropic', 'model' => 'claude-candidate', 'reasoning' => 'high'],
        ],
        'judge' => ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'provider_default'],
        'cost_cap_usd' => null,
        'confirm_estimate_usd' => 0,
        ...$overrides,
    ];
}

function benchmarkInertiaVersion(mixed $test, BenchmarkRun $run): string
{
    return $test->get(route('admin.benchmarks.runs.show', $run))->viewData('page')['version'];
}

test('benchmark run pages are admin only', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->completed()->create();

    $this->actingAs(User::factory()->member()->create());

    $this->get(route('admin.benchmarks.runs.index'))->assertForbidden();
    $this->get(route('admin.benchmarks.runs.create'))->assertForbidden();
    $this->get(route('admin.benchmarks.runs.show', $benchmarkRun))->assertForbidden();
    $this->post(route('admin.benchmarks.runs.estimate'), [])->assertForbidden();
    $this->post(route('admin.benchmarks.runs.store'), [])->assertForbidden();
    $this->post(route('admin.benchmarks.runs.cancel', $benchmarkRun))->assertForbidden();
    $this->post(route('admin.benchmarks.runs.rejudge', $benchmarkRun))->assertForbidden();
    $this->delete(route('admin.benchmarks.runs.destroy', $benchmarkRun))->assertForbidden();
});

test('benchmark run pages are hidden when ai is off', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.benchmarks.runs.index'))->assertNotFound();
});

test('the runs index lists runs newest first', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    BenchmarkRun::factory()->completed()->create(['name' => 'Older', 'created_at' => now()->subDay()]);
    BenchmarkRun::factory()->running()->create(['name' => 'Newer', 'case_ids' => [1, 2, 3]]);

    $this->get(route('admin.benchmarks.runs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Runs/Index')
            ->where('runs.data.0.name', 'Newer')
            ->where('runs.data.0.status_label', 'Running')
            ->where('runs.data.0.case_count', 3)
            ->where('runs.data.1.name', 'Older')
            ->where('runs.meta.total', 2));
});

test('the setup page lists cases, the judge default and the current tiers', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $decision = BenchmarkCase::factory()->create(['name' => 'Stuck download', 'tags' => ['smoke']]);
    AiTaskModel::query()->create(['task' => AiTask::Decision, 'scope' => AiTaskModel::DEFAULT_SCOPE, 'position' => 0, 'provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'high']);
    AiTaskModel::query()->create(['task' => AiTask::Decision, 'scope' => AiTaskModel::DEFAULT_SCOPE, 'position' => 1, 'provider' => 'anthropic', 'model' => 'claude-candidate']);
    AiTaskModel::query()->create(['task' => AiTask::BenchmarkJudge, 'scope' => AiTaskModel::DEFAULT_SCOPE, 'position' => 0, 'provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'low']);

    $this->get(route('admin.benchmarks.runs.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Runs/Create')
            ->where('cases.0.id', $decision->id)
            ->where('cases.0.task_label', 'Decision agent')
            ->where('cases.0.tags', ['smoke'])
            ->has('tasks')
            ->has('models')
            ->has('providers')
            ->has('reasoningLevels')
            ->has('modelCapabilities')
            ->has('reasoningProviders')
            ->where('judgeDefault', ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'low'])
            ->where('currentTiers.decision.0', ['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'high'])
            ->where('currentTiers.decision.1', ['provider' => 'anthropic', 'model' => 'claude-candidate', 'reasoning' => 'provider_default'])
            ->has('currentTiers.decision', 2)
            ->has('currentTiers.title', 1)
            ->missing('currentTiers.benchmark_judge')
            ->missing('currentTiers.failover'));
});

test('the estimate endpoint returns totals, rows and the unpriced list', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $case = BenchmarkCase::factory()->create();

    $payload = benchmarkRunPayload([$case], ['name' => null]);
    unset($payload['confirm_estimate_usd']);

    $this->postJson(route('admin.benchmarks.runs.estimate'), $payload)
        ->assertOk()
        ->assertJsonPath('rows.0.candidate_index', 0)
        ->assertJsonPath('rows.0.priced', true)
        ->assertJsonPath('rows.1.priced', true)
        ->assertJsonPath('unpriced', [])
        ->assertJsonPath('total_usd', 0.0215);
});

test('candidates must be priced models and the list is capped', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $case = BenchmarkCase::factory()->create();

    $payload = benchmarkRunPayload([$case]);
    $payload['candidates'][0]['model'] = 'not-priced';

    $this->postJson(route('admin.benchmarks.runs.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['candidates.0.model']);

    $payload = benchmarkRunPayload([$case]);
    $payload['candidates'] = array_fill(0, 9, $payload['candidates'][0]);

    $this->postJson(route('admin.benchmarks.runs.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['candidates']);

    $payload = benchmarkRunPayload([$case], ['judge' => ['provider' => 'openai', 'model' => 'nope', 'reasoning' => null]]);

    $this->postJson(route('admin.benchmarks.runs.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['judge.model']);

    $this->postJson(route('admin.benchmarks.runs.store'), benchmarkRunPayload([$case], ['case_ids' => [999999]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['case_ids.0']);
});

test('store refuses a stale estimate', function (): void {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $case = BenchmarkCase::factory()->create();

    $this->postJson(route('admin.benchmarks.runs.store'), benchmarkRunPayload([$case], ['confirm_estimate_usd' => 5]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirm_estimate_usd'])
        ->assertJsonPath('errors.confirm_estimate_usd.0', 'The estimate changed — review it again.');

    expect(BenchmarkRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('store launches the run and redirects to it', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $cases = [BenchmarkCase::factory()->create(), BenchmarkCase::factory()->create()];

    $estimate = $this->postJson(route('admin.benchmarks.runs.estimate'), benchmarkRunPayload($cases))->json('total_usd');

    $response = $this->post(route('admin.benchmarks.runs.store'), benchmarkRunPayload($cases, ['confirm_estimate_usd' => $estimate + 0.005, 'cost_cap_usd' => 2.5]));

    $benchmarkRun = BenchmarkRun::query()->firstOrFail();

    $response->assertRedirect(route('admin.benchmarks.runs.show', $benchmarkRun));
    expect($benchmarkRun->name)->toBe('Spring bake-off')
        ->and($benchmarkRun->status)->toBe(BenchmarkRunStatus::Running)
        ->and((float) $benchmarkRun->cost_cap_usd)->toBe(2.5)
        ->and($benchmarkRun->created_by)->toBe($admin->id)
        ->and($benchmarkRun->results()->count())->toBe(4);
    Queue::assertPushed(RunBenchmarkCase::class, 4);
});

/**
 * Seeds a two-candidate run with two decision cases and one title case.
 *
 * @return array{BenchmarkRun, BenchmarkCase, BenchmarkCase, BenchmarkCase}
 */
function seedBenchmarkRun(): array
{
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'provider_default'],
        ['provider' => 'anthropic', 'model' => 'claude-candidate', 'reasoning' => 'high'],
    ])->create(['judge' => ['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'low']]);
    $stuck = BenchmarkCase::factory()->create(['name' => 'Stuck download', 'input' => ['prompt' => 'Stuck at 99%'], 'scoring' => ['type' => 'actions', 'expected' => []]]);
    $slow = BenchmarkCase::factory()->create(['name' => 'Slow import']);
    $title = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Expanse title']);

    $make = fn (BenchmarkCase $case, int $candidate, array $attributes) => BenchmarkResult::factory()->forCase($run, $case, $candidate)->create($attributes);

    $make($stuck, 0, ['status' => BenchmarkResultStatus::Ok, 'score' => 1.0, 'passed' => true, 'latency_ms' => 1000, 'cost_usd' => 0.01, 'judge_cost_usd' => 0, 'tool_misses' => 1, 'output' => 'queued a retry', 'score_detail' => ['matched' => ['retry'], 'tool_log' => [['tool' => 'search', 'hit' => true]]]]);
    $make($stuck, 1, ['status' => BenchmarkResultStatus::Ok, 'score' => 0.5, 'passed' => false, 'latency_ms' => 3000, 'cost_usd' => 0.02, 'judge_cost_usd' => 0.002]);
    $make($slow, 0, ['status' => BenchmarkResultStatus::Ok, 'score' => 0.5, 'passed' => false, 'latency_ms' => 2000, 'cost_usd' => 0.03, 'judge_cost_usd' => 0, 'tool_misses' => 2]);
    $make($slow, 1, ['status' => BenchmarkResultStatus::Failed, 'error' => 'Provider timeout', 'cost_usd' => 0, 'judge_cost_usd' => 0]);
    $make($title, 0, ['status' => BenchmarkResultStatus::Ok, 'score' => 0.8, 'passed' => true, 'latency_ms' => 500, 'cost_usd' => 0.001]);
    $make($title, 1, ['status' => BenchmarkResultStatus::Skipped]);

    return [$run, $stuck, $slow, $title];
}

test('show returns the run, counts and the aggregated matrix', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$run, $stuck] = seedBenchmarkRun();

    $this->get(route('admin.benchmarks.runs.show', $run))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Runs/Show')
            ->where('run.id', $run->id)
            ->where('run.status_label', 'Completed')
            ->where('run.counts', ['total' => 6, 'pending' => 0, 'ok' => 4, 'failed' => 1, 'skipped' => 1])
            ->has('matrix', 2)
            ->where('matrix.1.task', 'decision')
            ->where('matrix.1.task_label', 'Decision agent')
            ->where('matrix.1.candidates.0.avg_score', 0.75)
            ->where('matrix.1.candidates.0.pass_rate', 0.5)
            ->where('matrix.1.candidates.0.tool_misses', 3)
            ->where('matrix.1.candidates.0.self_judged', true)
            ->where('matrix.1.candidates.1.self_judged', false)
            ->where('matrix.1.candidates.1.ok', 1)
            ->where('matrix.1.candidates.1.failed', 1)
            ->where('matrix.1.candidates.1.reasoning_label', 'High')
            ->where('matrix.1.best.avg_score', 0)
            ->where('matrix.1.best.pass_rate', 0)
            ->where('matrix.1.best.latency_p50', 0)
            ->where('matrix.1.cases.0.case_id', $stuck->id)
            ->has('matrix.1.cases.0.cells', 2)
            ->where('matrix.1.cases.0.cells.0.judge_status', 'none')
            ->missing('resultDetail'));
});

test('the drawer partial returns every candidate result for the clicked case', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$run, $stuck] = seedBenchmarkRun();
    $benchmarkResult = $run->results()->where('benchmark_case_id', $stuck->id)->where('candidate_index', 1)->firstOrFail();

    $this->get(route('admin.benchmarks.runs.show', ['benchmarkRun' => $run, 'result' => $benchmarkResult->id]), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Admin/Benchmarks/Runs/Show',
        'X-Inertia-Partial-Data' => 'resultDetail',
        'X-Inertia-Version' => benchmarkInertiaVersion($this, $run),
    ])
        ->assertOk()
        ->assertJsonPath('props.resultDetail.case_name', 'Stuck download')
        ->assertJsonPath('props.resultDetail.prompt', 'Stuck at 99%')
        ->assertJsonPath('props.resultDetail.scoring.type', 'actions')
        ->assertJsonCount(2, 'props.resultDetail.results')
        ->assertJsonPath('props.resultDetail.results.0.candidate_index', 0)
        ->assertJsonPath('props.resultDetail.results.0.output', 'queued a retry')
        ->assertJsonPath('props.resultDetail.results.0.tool_log.0.tool', 'search')
        ->assertJsonPath('props.resultDetail.results.0.score_detail.matched', ['retry'])
        ->assertJsonPath('props.resultDetail.results.1.candidate_index', 1)
        ->assertJsonPath('props.resultDetail.results.1.score', 0.5)
        ->assertJsonPath('props.resultDetail.results.1.judge_status', 'none');
});

test('a result from another run is not found', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$run] = seedBenchmarkRun();
    $other = BenchmarkResult::factory()->create();

    $this->get(route('admin.benchmarks.runs.show', ['benchmarkRun' => $run, 'result' => $other->id]), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Admin/Benchmarks/Runs/Show',
        'X-Inertia-Partial-Data' => 'resultDetail',
        'X-Inertia-Version' => benchmarkInertiaVersion($this, $run),
    ])->assertNotFound();
});

test('deleting a case keeps the run page working with the copied name', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    [$run, $stuck] = seedBenchmarkRun();
    $resultId = $run->results()->where('benchmark_case_id', $stuck->id)->value('id');
    $stuck->delete();

    $this->get(route('admin.benchmarks.runs.show', $run))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('matrix.1.cases.0.case_id', null)
            ->where('matrix.1.cases.0.case_name', 'Stuck download')
            ->has('matrix.1.cases.0.cells', 2));

    $this->get(route('admin.benchmarks.runs.show', ['benchmarkRun' => $run, 'result' => $resultId]), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Admin/Benchmarks/Runs/Show',
        'X-Inertia-Partial-Data' => 'resultDetail',
        'X-Inertia-Version' => benchmarkInertiaVersion($this, $run),
    ])
        ->assertOk()
        ->assertJsonPath('props.resultDetail.case_name', 'Stuck download')
        ->assertJsonPath('props.resultDetail.scoring', null)
        ->assertJsonPath('props.resultDetail.prompt', null)
        ->assertJsonCount(2, 'props.resultDetail.results');
});

test('cancelling stops a running run and skips its pending results', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $run = BenchmarkRun::factory()->running()->create();
    BenchmarkResult::factory()->for($run, 'run')->create(['status' => BenchmarkResultStatus::Pending]);

    $this->post(route('admin.benchmarks.runs.cancel', $run))->assertRedirect();

    expect($run->refresh()->status)->toBe(BenchmarkRunStatus::Cancelled)
        ->and($run->results()->first()->status)->toBe(BenchmarkResultStatus::Skipped);
});

test('cancelling a finished run changes nothing', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $run = BenchmarkRun::factory()->completed()->create();

    $this->post(route('admin.benchmarks.runs.cancel', $run))->assertRedirect();

    expect($run->refresh()->status)->toBe(BenchmarkRunStatus::Completed);
});

test('re-judging queues a judge-only job per unscored result', function (): void {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $run = BenchmarkRun::factory()->completed()->create();
    $unscored = BenchmarkResult::factory()->for($run, 'run')->create(['status' => BenchmarkResultStatus::Ok, 'judge_status' => BenchmarkScorer::JUDGE_FAILED]);
    BenchmarkResult::factory()->for($run, 'run')->scored(0.9, true)->create();

    $this->post(route('admin.benchmarks.runs.rejudge', $run))->assertRedirect();

    Queue::assertPushed(RunBenchmarkCase::class, 1);
    Queue::assertPushed(RunBenchmarkCase::class, fn (RunBenchmarkCase $job): bool => $job->resultId === $unscored->id && $job->judgeOnly);
});

test('re-judging with nothing unscored queues nothing', function (): void {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $run = BenchmarkRun::factory()->completed()->create();

    $this->post(route('admin.benchmarks.runs.rejudge', $run))->assertRedirect();

    Queue::assertNothingPushed();
});

test('a finished run can be deleted with its results', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $run = BenchmarkRun::factory()->completed()->create();
    BenchmarkResult::factory()->for($run, 'run')->create();

    $this->delete(route('admin.benchmarks.runs.destroy', $run))->assertRedirect(route('admin.benchmarks.runs.index'));

    expect(BenchmarkRun::query()->count())->toBe(0)
        ->and(BenchmarkResult::query()->where('benchmark_run_id', $run->id)->count())->toBe(0);
});

test('a running run cannot be deleted', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $run = BenchmarkRun::factory()->running()->create();

    $this->delete(route('admin.benchmarks.runs.destroy', $run))->assertRedirect();

    expect(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeTrue();
});
