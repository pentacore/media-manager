<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkNeutralRejudgeStatus;
use App\Models\AiModelPrice;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
});

/**
 * @return array{0: BenchmarkRun, 1: BenchmarkRun, 2: BenchmarkResult, 3: BenchmarkResult}
 */
function comparePagePair(): array
{
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Shared']);
    $candidates = [['provider' => 'openai', 'model' => 'gpt-a', 'reasoning' => null]];
    $runA = BenchmarkRun::factory()->completed()->withCandidates($candidates)->create(['name' => 'Run A', 'judge' => ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => null]]);
    $runB = BenchmarkRun::factory()->completed()->withCandidates($candidates)->create(['name' => 'Run B', 'judge' => ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => null]]);
    $before = BenchmarkResult::factory()->forCase($runA, $benchmarkCase, 0)->scored(0.8, true)->create(['output' => 'Old', 'score_detail' => ['rationale' => 'Fine', 'tool_log' => []]]);
    $after = BenchmarkResult::factory()->forCase($runB, $benchmarkCase, 0)->scored(0.5, false)->create(['output' => 'New', 'score_detail' => ['rationale' => 'Worse', 'tool_log' => []]]);

    return [$runA, $runB, $before, $after];
}

function comparePageUrl(BenchmarkRun ...$runs): string
{
    return route('admin.benchmarks.compare.show', ['runs' => implode(',', array_map(static fn (BenchmarkRun $run): int => $run->id, $runs))]);
}

test('the comparison page is admin only', function (): void {
    [$runA, $runB] = comparePagePair();
    $this->actingAs(User::factory()->member()->create());

    $this->get(comparePageUrl($runA, $runB))->assertForbidden();
});

test('an admin sees the comparison of two runs', function (): void {
    [$runA, $runB] = comparePagePair();
    $running = BenchmarkRun::factory()->running()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->get(comparePageUrl($runB, $runA))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Benchmarks/Compare')
            ->where('comparison.runs.0.id', $runA->id)
            ->where('comparison.runs.0.is_baseline', true)
            ->where('comparison.tasks.0.task', 'title')
            ->where('comparison.score_source.current', 'original')
            ->where('rejudge', null)
            ->where('judgeOptions.judgeDefault.provider', 'openai')
            ->where('pickableRuns', fn ($runs): bool => collect($runs)->pluck('id')->doesntContain($running->id) && collect($runs)->pluck('id')->contains($runA->id))
            ->missing('resultDetail')
            ->missing('outputs'));
});

test('a comparison of too few, too many, repeated or unfinished runs goes back to the runs list', function (Closure $runs): void {
    [$runA, $runB] = comparePagePair();
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.benchmarks.compare.show', ['runs' => $runs($runA, $runB)]))
        ->assertRedirect(route('admin.benchmarks.runs.index'))
        ->assertInertiaFlash('toast.type', 'error');
})->with([
    'one run' => [fn (BenchmarkRun $a, BenchmarkRun $b): string => (string) $a->id],
    'six runs' => [fn (BenchmarkRun $a, BenchmarkRun $b): string => implode(',', [$a->id, $b->id, ...BenchmarkRun::factory()->completed()->count(4)->create()->pluck('id')->all()])],
    'a repeated run' => [fn (BenchmarkRun $a, BenchmarkRun $b): string => sprintf('%d,%d', $a->id, $a->id)],
    'a running run' => [fn (BenchmarkRun $a, BenchmarkRun $b): string => sprintf('%d,%d', $a->id, BenchmarkRun::factory()->running()->create()->id)],
    'a missing run' => [fn (BenchmarkRun $a, BenchmarkRun $b): string => sprintf('%d,999999', $a->id)],
    'nonsense' => [fn (BenchmarkRun $a, BenchmarkRun $b): string => 'abc'],
]);

test('a baseline outside the compared runs is refused', function (): void {
    [$runA, $runB] = comparePagePair();
    $other = BenchmarkRun::factory()->completed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.benchmarks.compare.show', ['runs' => sprintf('%d,%d', $runA->id, $runB->id), 'baseline' => $other->id]))
        ->assertRedirect(route('admin.benchmarks.runs.index'));
});

/**
 * @return array<string, string>
 */
function comparePartialHeaders(mixed $test, string $url, string $prop): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Admin/Benchmarks/Compare',
        'X-Inertia-Partial-Data' => $prop,
        'X-Inertia-Version' => $test->get($url)->viewData('page')['version'],
    ];
}

test('a result of a compared run opens in the drawer and others are not found', function (): void {
    [$runA, $runB, $before] = comparePagePair();
    $stranger = BenchmarkResult::factory()->scored(0.1, false)->create();
    $this->actingAs(User::factory()->admin()->create());
    $url = comparePageUrl($runA, $runB);

    $this->get($url.'&result='.$before->id, comparePartialHeaders($this, $url, 'resultDetail'))
        ->assertOk()
        ->assertJsonPath('props.resultDetail.case_name', 'Shared')
        ->assertJsonPath('props.resultDetail.results.0.output', 'Old');

    $this->get($url.'&result='.$stranger->id, comparePartialHeaders($this, $url, 'resultDetail'))->assertNotFound();
});

test('outputs show both sides of a case', function (): void {
    [$runA, $runB, $before, $after] = comparePagePair();
    $this->actingAs(User::factory()->admin()->create());
    $url = comparePageUrl($runA, $runB);

    $this->get(sprintf('%s&outputs=%d,%d', $url, $before->id, $after->id), comparePartialHeaders($this, $url, 'outputs'))
        ->assertOk()
        ->assertJsonPath('props.outputs.0.output', 'Old')
        ->assertJsonPath('props.outputs.1.output', 'New')
        ->assertJsonPath('props.outputs.1.rationale', 'Worse');
});

test('the latest re-judge of exactly these runs is summarised', function (): void {
    [$runA, $runB, $before, $after] = comparePagePair();
    $judge = ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => null];
    BenchmarkNeutralRejudge::factory()->forRuns([$runA->id], $judge)->create();
    $rejudge = BenchmarkNeutralRejudge::factory()->running()->forRuns([$runB->id, $runA->id], $judge)->create(['cost_usd' => '0.001000']);
    BenchmarkNeutralJudgement::factory()->forResult($before, $judge)->create(['benchmark_neutral_rejudge_id' => $rejudge->id]);
    BenchmarkNeutralJudgement::factory()->forResult($after, $judge)->queued()->create(['benchmark_neutral_rejudge_id' => $rejudge->id]);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(comparePageUrl($runA, $runB))
        ->assertInertia(fn ($page) => $page
            ->where('rejudge.id', $rejudge->id)
            ->where('rejudge.status', BenchmarkNeutralRejudgeStatus::Running->value)
            ->where('rejudge.judge_label', 'openai · gpt-judge')
            ->where('rejudge.total', 2)
            ->where('rejudge.done', 1)
            ->where('rejudge.failed', 0)
            ->where('rejudge.cost_usd', 0.001));
});
