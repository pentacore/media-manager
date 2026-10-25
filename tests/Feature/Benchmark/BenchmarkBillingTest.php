<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkContextStarter;
use App\Services\Benchmark\BenchmarkScorer;
use App\Services\Benchmark\CaseOutcome;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-candidate', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-neutral', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
});

function billingResult(): BenchmarkResult
{
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['input' => ['prompt' => 'Name this chat']]);
    $benchmarkRun = BenchmarkRun::factory()->completed()->create([
        'candidates' => [['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'provider_default']],
        'judge' => ['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'provider_default'],
    ]);

    return BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->scored(0.6, false)->create([
        'output' => 'Chat about films',
        'completion_tokens' => 20,
        'score_detail' => ['tool_log' => [['tool' => 'SearchMediaTool', 'arguments' => ['q' => 'x'], 'result' => 'ok', 'hit' => true]]],
    ])->fresh();
}

test('a stored result rebuilds the outcome the judge saw', function (): void {
    $caseOutcome = CaseOutcome::fromResult(billingResult());

    expect($caseOutcome->output)->toBe('Chat about films')
        ->and($caseOutcome->toolLog)->toBe([['tool' => 'SearchMediaTool', 'arguments' => ['q' => 'x'], 'result' => 'ok', 'hit' => true]])
        ->and($caseOutcome->completionTokens)->toBe(20)
        ->and($caseOutcome->invocationId)->toBeNull();
});

test('a judge call outside the run bill is a benchmark usage row with no run', function (): void {
    $benchmarkResult = billingResult();
    BenchmarkJudgeAgent::fake(fn (): array => ['score' => 9, 'rationale' => 'Neutral']);
    $benchmarkRunContext = resolve(BenchmarkRunContext::class);

    resolve(BenchmarkContextStarter::class)->start($benchmarkRunContext, $benchmarkResult->run, $benchmarkResult->benchmarkCase, 0, ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null], billsRun: false);

    try {
        $scores = resolve(BenchmarkScorer::class)->score($benchmarkResult->benchmarkCase, CaseOutcome::fromResult($benchmarkResult));
    } finally {
        $benchmarkRunContext->stop();
    }

    $aiUsageRecord = AiUsageRecord::query()->sole();

    expect($scores['score'])->toBe(0.9)
        ->and($aiUsageRecord->is_benchmark)->toBeTrue()
        ->and($aiUsageRecord->benchmark_run_id)->toBeNull()
        ->and($aiUsageRecord->model)->toBe('gpt-neutral')
        ->and($benchmarkResult->fresh()->score)->toBe(0.6);
});

test('a billing context reports its run id and a non-billing one does not', function (): void {
    $benchmarkResult = billingResult();
    $benchmarkRunContext = resolve(BenchmarkRunContext::class);
    $benchmarkContextStarter = resolve(BenchmarkContextStarter::class);

    $benchmarkContextStarter->start($benchmarkRunContext, $benchmarkResult->run, $benchmarkResult->benchmarkCase, 0);

    expect($benchmarkRunContext->billedRunId())->toBe($benchmarkResult->benchmark_run_id);
    $benchmarkRunContext->stop();

    $benchmarkContextStarter->start($benchmarkRunContext, $benchmarkResult->run, $benchmarkResult->benchmarkCase, 0, billsRun: false);
    expect($benchmarkRunContext->billedRunId())->toBeNull()
        ->and($benchmarkRunContext->runId())->toBe($benchmarkResult->benchmark_run_id);
    $benchmarkRunContext->stop();

    expect(fn () => $benchmarkContextStarter->start($benchmarkRunContext, $benchmarkResult->run, $benchmarkResult->benchmarkCase, 3))
        ->toThrow(RuntimeException::class, 'The run has no candidate #3.');
});
