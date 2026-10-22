<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkNeutralRejudgeStatus;
use App\Enums\QueueLane;
use App\Jobs\RunNeutralJudgement;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\Benchmark\BenchmarkNeutralRejudgeLauncher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-candidate', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-neutral', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
});

/**
 * @return array{provider: string, model: string, reasoning: string|null}
 */
function neutralJudge(): array
{
    return ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null];
}

/**
 * Two finished runs over one judged title case and one decision case, plus a
 * title case only the first run has.
 *
 * @return array{runs: list<int>, judged: list<BenchmarkResult>, decision: list<BenchmarkResult>, extra: BenchmarkResult}
 */
function neutralFixture(): array
{
    $judgedCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Judged', 'input' => ['prompt' => 'Name this chat']]);
    $decisionCase = BenchmarkCase::factory()->create(['name' => 'Decision']);
    $extraCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Only first']);
    $runs = [];
    $judged = [];
    $decision = [];

    foreach ([0, 1] as $position) {
        $benchmarkRun = BenchmarkRun::factory()->completed()->create([
            'candidates' => [['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => null]],
            'judge' => ['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => null],
        ]);
        $runs[] = $benchmarkRun->id;
        $judged[] = BenchmarkResult::factory()->forCase($benchmarkRun, $judgedCase, 0)->scored(0.8, true)->create(['output' => 'Films chat', 'completion_tokens' => 100, 'score_detail' => ['tool_log' => []]]);
        $decision[] = BenchmarkResult::factory()->forCase($benchmarkRun, $decisionCase, 0)->scored(1.0, true)->create();

        if ($position === 0) {
            $extra = BenchmarkResult::factory()->forCase($benchmarkRun, $extraCase, 0)->scored(0.5, false)->create();
        }
    }

    return ['runs' => $runs, 'judged' => $judged, 'decision' => $decision, 'extra' => $extra];
}

function fakeNeutralJudge(int $score = 6): void
{
    BenchmarkJudgeAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
        ['score' => $score, 'rationale' => 'Neutral take'],
        sprintf('{"score":%d,"rationale":"Neutral take"}', $score),
        new TextUsage(200_000, 50_000),
        new Meta('openai', 'gpt-neutral'),
    ));
}

/**
 * Runs the job's handle() directly: dispatchSync() would be swallowed by
 * Queue::fake().
 */
function runNeutralJudgement(int $judgementId): void
{
    $runNeutralJudgement = new RunNeutralJudgement($judgementId);

    app()->call($runNeutralJudgement->handle(...));
}

function neutralLauncher(): BenchmarkNeutralRejudgeLauncher
{
    return resolve(BenchmarkNeutralRejudgeLauncher::class);
}

test('the scope is the judged results on common cases that still need this judge', function (): void {
    $fixture = neutralFixture();
    $key = BenchmarkNeutralJudgement::keyFor(neutralJudge());

    expect(neutralLauncher()->scope($fixture['runs'], $key)->pluck('id')->all())->toBe([$fixture['judged'][0]->id, $fixture['judged'][1]->id]);

    BenchmarkNeutralJudgement::factory()->forResult($fixture['judged'][0], neutralJudge())->create();
    BenchmarkNeutralJudgement::factory()->forResult($fixture['judged'][1], neutralJudge())->failed('boom')->create();

    expect(neutralLauncher()->scope($fixture['runs'], $key)->pluck('id')->all())->toBe([$fixture['judged'][1]->id]);
});

test('the estimate prices each result by its own output length', function (): void {
    $fixture = neutralFixture();
    $results = neutralLauncher()->scope($fixture['runs'], BenchmarkNeutralJudgement::keyFor(neutralJudge()));

    $estimate = neutralLauncher()->estimate($results, neutralJudge());
    $unpriced = neutralLauncher()->estimate($results, ['provider' => 'openai', 'model' => 'gpt-unknown', 'reasoning' => null]);

    // (1500 + 100 * 2) prompt tokens at $1/M plus 300 completion at $4/M, twice.
    expect($estimate)->toBe(['total_usd' => 0.0058, 'results' => 2, 'unpriced' => []])
        ->and($unpriced)->toBe(['total_usd' => 0.0, 'results' => 2, 'unpriced' => ['openai/gpt-unknown']]);
});

test('starting queues one judgement per result and retries failed ones', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $failed = BenchmarkNeutralJudgement::factory()->forResult($fixture['judged'][1], neutralJudge())->failed('boom')->create();
    $user = User::factory()->admin()->create();

    $rejudge = neutralLauncher()->start(array_reverse($fixture['runs']), neutralJudge(), 1.0, 0.0058, $user);

    expect($rejudge)->toBeInstanceOf(BenchmarkNeutralRejudge::class)
        ->and($rejudge->run_ids)->toBe($fixture['runs'])
        ->and($rejudge->status)->toBe(BenchmarkNeutralRejudgeStatus::Running)
        ->and($rejudge->created_by)->toBe($user->id)
        ->and($rejudge->judgements()->where('status', BenchmarkJudgeStatus::Queued)->count())->toBe(2)
        ->and($failed->fresh()->benchmark_neutral_rejudge_id)->toBe($rejudge->id)
        ->and($failed->fresh()->detail)->toBeNull();
    Queue::assertPushed(RunNeutralJudgement::class, 2);
});

test('a second re-judge skips results another re-judge has in flight', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $user = User::factory()->admin()->create();

    $first = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, $user);
    $second = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, $user);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(BenchmarkNeutralRejudge::query()->count())->toBe(1)
        ->and(BenchmarkNeutralJudgement::query()->count())->toBe(2);
    Queue::assertPushed(RunNeutralJudgement::class, 2);
});

test('a neutral judgement scores the stored output without touching the run', function (): void {
    fakeNeutralJudge(6);
    $fixture = neutralFixture();

    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 5.0, 0.0058, User::factory()->admin()->create());

    $benchmarkNeutralJudgement = BenchmarkNeutralJudgement::query()->where('benchmark_result_id', $fixture['judged'][0]->id)->sole();
    $benchmarkRun = BenchmarkRun::query()->find($fixture['runs'][0]);

    expect($benchmarkNeutralJudgement->status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and($benchmarkNeutralJudgement->score)->toBe(0.6)
        ->and($benchmarkNeutralJudgement->passed)->toBeFalse()
        ->and($benchmarkNeutralJudgement->detail)->toBe(['score_10' => 6, 'rationale' => 'Neutral take'])
        ->and((float) $benchmarkNeutralJudgement->cost_usd)->toBe(0.4)
        ->and((float) $rejudge->fresh()->cost_usd)->toBe(0.8)
        ->and($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::Completed)
        ->and($fixture['judged'][0]->fresh()->score)->toBe(0.8)
        ->and((float) $benchmarkRun->cost_usd)->toBe(0.0)
        ->and(AiUsageRecord::query()->whereNotNull('benchmark_run_id')->count())->toBe(0)
        ->and(AiUsageRecord::query()->where('is_benchmark', true)->count())->toBe(2);
});

test('the cap stops the re-judge and fails what is still queued', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 0.5, 0.0058, User::factory()->admin()->create());
    $rejudge->update(['cost_usd' => '0.500000']);
    fakeNeutralJudge();

    runNeutralJudgement($rejudge->judgements()->orderBy('id')->value('id'));

    expect($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::StoppedCostCap)
        ->and($rejudge->judgements()->pluck('status')->unique()->all())->toBe([BenchmarkJudgeStatus::Failed])
        ->and($rejudge->judgements()->first()->detail)->toBe(['error' => 'Cost cap reached']);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('cancelling fails the queued judgements and a late finish leaves it cancelled', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, User::factory()->admin()->create());
    [$inFlight, $queued] = $rejudge->judgements()->orderBy('id')->get()->all();
    $inFlight->update(['status' => BenchmarkJudgeStatus::Pending]);

    expect(neutralLauncher()->cancel($rejudge))->toBeTrue()
        ->and(neutralLauncher()->cancel($rejudge->fresh()))->toBeFalse()
        ->and($queued->fresh()->status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($queued->fresh()->detail)->toBe(['error' => 'Cancelled']);

    $inFlight->update(['status' => BenchmarkJudgeStatus::Ok, 'score' => 0.7, 'passed' => true]);
    neutralLauncher()->settle($rejudge->id);

    expect($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::Cancelled)
        ->and($inFlight->fresh()->score)->toBe(0.7);
});

test('a cancelled re-judge stays cancelled when its last job finishes', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, User::factory()->admin()->create());
    $judgementId = $rejudge->judgements()->orderBy('id')->value('id');
    $rejudge->update(['status' => BenchmarkNeutralRejudgeStatus::Cancelled, 'finished_at' => now()]);
    fakeNeutralJudge();

    runNeutralJudgement($judgementId);

    expect(BenchmarkNeutralJudgement::query()->find($judgementId)->detail)->toBe(['error' => 'Cancelled'])
        ->and($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::Cancelled);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('a refused budget fails the judgement with the reason', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, User::factory()->admin()->create());
    $this->mock(AiBudgetGuard::class)->shouldReceive('enforce')->andThrow(new AiBudgetExceededException(10.0, 10.0));
    $judgementId = $rejudge->judgements()->orderBy('id')->value('id');

    runNeutralJudgement($judgementId);

    $judgement = BenchmarkNeutralJudgement::query()->find($judgementId);

    expect($judgement->status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($judgement->detail['error'])->toContain('Monthly AI hard cap reached');
});

test('a judgement whose case was deleted fails', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, User::factory()->admin()->create());
    $judgementId = $rejudge->judgements()->orderBy('id')->value('id');
    BenchmarkResult::query()->whereKey($fixture['judged'][0]->id)->update(['benchmark_case_id' => null]);

    runNeutralJudgement($judgementId);

    expect(BenchmarkNeutralJudgement::query()->find($judgementId)->detail)->toBe(['error' => 'Case was deleted']);
});

test('the stalled sweep fails lost judgements but leaves queued ones waiting', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, User::factory()->admin()->create());
    [$waiting, $claimed] = $rejudge->judgements()->orderBy('id')->get()->all();
    $old = now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 60);
    $waiting->forceFill(['updated_at' => $old])->saveQuietly();
    $claimed->forceFill(['status' => BenchmarkJudgeStatus::Pending, 'updated_at' => $old])->saveQuietly();

    Artisan::call('benchmark:settle-stalled');

    expect($waiting->fresh()->status)->toBe(BenchmarkJudgeStatus::Queued)
        ->and($claimed->fresh()->status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($claimed->fresh()->detail)->toBe(['error' => 'Judge did not finish'])
        ->and($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::Running);

    $waiting->forceFill(['updated_at' => now()->subSeconds(RunNeutralJudgement::LOST_AFTER_SECONDS + 1)])->saveQuietly();

    Artisan::call('benchmark:settle-stalled');

    expect($waiting->fresh()->status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($waiting->fresh()->detail)->toBe(['error' => 'Judge did not finish'])
        ->and($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::Completed);
});

test('the stalled sweep settles a running re-judge with nothing open', function (): void {
    Queue::fake();
    $fixture = neutralFixture();
    $rejudge = neutralLauncher()->start($fixture['runs'], neutralJudge(), 1.0, 0.0058, User::factory()->admin()->create());
    $rejudge->judgements()->toBase()->update(['status' => BenchmarkJudgeStatus::Failed]);

    Artisan::call('benchmark:settle-stalled');

    expect($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::Completed);
});

test('the job is unique per judgement on the AI lane', function (): void {
    $runNeutralJudgement = new RunNeutralJudgement(7);

    expect($runNeutralJudgement)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($runNeutralJudgement->uniqueId())->toBe('neutral-judgement:7')
        ->and(new ReflectionClass(RunNeutralJudgement::class)->getAttributes(Illuminate\Queue\Attributes\Queue::class)[0]->getArguments()[0])->toBe(QueueLane::Ai);
});
