<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Enums\QueueLane;
use App\Jobs\Middleware\LimitBenchmarkConcurrency;
use App\Jobs\RunBenchmarkCase;
use App\Models\ActionRequest;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\Benchmark\BenchmarkRunLauncher;
use App\Settings\AiSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.anthropic.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-candidate', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function jobRun(array $attributes = []): BenchmarkRun
{
    return BenchmarkRun::factory()->running()->create([
        'candidates' => [['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => 'provider_default']],
        'judge' => ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'provider_default'],
        ...$attributes,
    ]);
}

function jobTitleCase(): BenchmarkCase
{
    return BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['input' => ['prompt' => 'What is the third season of The Expanse about?']]);
}

function jobFakeTitle(string $title = 'Expanse Season Three'): void
{
    TitleAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
        ['title' => $title],
        (string) json_encode(['title' => $title]),
        new TextUsage(100_000, 10_000),
        new Meta('openai', 'gpt-candidate'),
    ));
}

function jobFakeJudge(int $score = 8): void
{
    BenchmarkJudgeAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
        ['score' => $score, 'rationale' => 'Concise and accurate'],
        (string) json_encode(['score' => $score, 'rationale' => 'Concise and accurate']),
        new TextUsage(200_000, 50_000),
        new Meta('openai', 'gpt-judge'),
    ));
}

/**
 * A usage row the run's candidate (or judge) recorded, priced from the catalog.
 */
function jobSpend(BenchmarkRun $benchmarkRun, int $promptTokens, int $completionTokens, string $model = 'gpt-candidate'): AiUsageRecord
{
    return AiUsageRecord::factory()->benchmarked($benchmarkRun->id)->create([
        'provider' => 'openai',
        'model' => $model,
        'prompt_tokens' => $promptTokens,
        'completion_tokens' => $completionTokens,
    ]);
}

/**
 * Age a result's claim past the stale window, as if its worker died.
 */
function jobStaleClaim(BenchmarkResult $benchmarkResult): void
{
    BenchmarkResult::query()->whereKey($benchmarkResult->id)->toBase()->update(['updated_at' => now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 1)]);
}

function jobContextActive(): bool
{
    return resolve(BenchmarkRunContext::class)->active();
}

test('a title case runs, is judged and completes the run when it was the last result', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobSpend(BenchmarkRun::factory()->create(), 500_000, 0);
    jobFakeTitle();
    jobFakeJudge(8);

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();
    $benchmarkRun->refresh();

    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkResult->output)->toBe('Expanse Season Three')
        ->and($benchmarkResult->error)->toBeNull()
        ->and($benchmarkResult->score)->toBe(0.8)
        ->and($benchmarkResult->passed)->toBeTrue()
        ->and($benchmarkResult->judge_status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and($benchmarkResult->score_detail)->toMatchArray(['score_10' => 8, 'rationale' => 'Concise and accurate', 'tool_log' => []])
        ->and($benchmarkResult->prompt_tokens)->toBe(100_000)
        ->and($benchmarkResult->completion_tokens)->toBe(10_000)
        ->and($benchmarkResult->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and((float) $benchmarkResult->cost_usd)->toBe(0.28)
        ->and((float) $benchmarkResult->judge_cost_usd)->toBe(0.4)
        ->and((float) $benchmarkRun->cost_usd)->toBe(0.68)
        ->and($benchmarkRun->status)->toBe(BenchmarkRunStatus::Completed)
        ->and($benchmarkRun->finished_at)->not->toBeNull()
        ->and(jobContextActive())->toBeFalse();
    BenchmarkJudgeAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => $agentPrompt->model === 'gpt-judge'
        && str_contains($agentPrompt->prompt, 'Expanse Season Three'));
});

test('the run keeps running while other results are pending', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkCase = jobTitleCase();
    $first = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    jobFakeTitle();
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($first->id));

    expect($first->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Running)
        ->and($benchmarkRun->fresh()->finished_at)->toBeNull();
});

test('the candidate runs on the run selection with its reasoning, history and mode', function (): void {
    $benchmarkRun = jobRun(['candidates' => [
        ['provider' => 'openai', 'model' => 'gpt-other', 'reasoning' => 'provider_default'],
        ['provider' => 'anthropic', 'model' => 'claude-candidate', 'reasoning' => 'high'],
    ]]);
    $chatCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'And the second one?',
        'mode' => 'advisory',
        'history' => [
            ['role' => 'user', 'content' => 'Is Dune in the library?'],
            ['role' => 'assistant', 'content' => 'Yes, Dune (2021) is there.'],
        ],
    ]]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $chatCase, 1)->create();
    $seen = [];
    MediaAgent::fake([function (string $prompt, mixed $attachments, mixed $provider, string $model) use (&$seen): string {
        $benchmarkRunContext = resolve(BenchmarkRunContext::class);
        $seen = [
            'model' => $model,
            'reasoning' => $benchmarkRunContext->selectionFor(AiTask::Chat)?->reasoning->value,
            'mode' => $benchmarkRunContext->mode()?->value,
            'history' => array_map(static fn (Message $message): array => [$message::class, $message->content], $benchmarkRunContext->history()),
        ];

        return 'Dune: Part Two is not in the library.';
    }]);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($seen)->toBe([
            'model' => 'claude-candidate',
            'reasoning' => 'high',
            'mode' => 'advisory',
            'history' => [
                [UserMessage::class, 'Is Dune in the library?'],
                [AssistantMessage::class, 'Yes, Dune (2021) is there.'],
            ],
        ]);
});

test('a provider exception fails the result with a sanitised message and still completes the run', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    TitleAgent::fake(fn (): never => throw new RuntimeException('Request to https://api.example.test/v1/chat?api_key=sk-secret-123 failed with 429'));
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();

    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->error)->toContain('failed with 429')
        ->and($benchmarkResult->error)->not->toContain('sk-secret-123')
        ->and($benchmarkResult->score)->toBeNull()
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and(jobContextActive())->toBeFalse();
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('a judge exception keeps the output unscored and still completes the run', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();
    BenchmarkJudgeAgent::fake(fn (): never => throw new RuntimeException('judge overloaded'));

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();

    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkResult->output)->toBe('Expanse Season Three')
        ->and($benchmarkResult->judge_status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($benchmarkResult->score)->toBeNull()
        ->and($benchmarkResult->passed)->toBeNull()
        ->and($benchmarkResult->score_detail)->toMatchArray(['error' => 'judge overloaded', 'tool_log' => []])
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.28)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and(jobContextActive())->toBeFalse();
});

test('an unexpected scoring shape fails the result but keeps the spent tokens and completes the run', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Decision)->create(['scoring' => ['type' => 'actions', 'expected' => ['garbage']]]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    DecisionAgent::fake([
        new ToolCall(id: 'c1', name: 'ProposeActionTool', arguments: ['type' => 'sonarr_search', 'target_service' => 'sonarr', 'rationale' => 'Missing', 'payload' => ['series_id' => 7]]),
        fn (): TextResponse => new TextResponse('Nothing to do.', new TextUsage(100_000, 10_000), new Meta('openai', 'gpt-candidate')),
    ]);

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();

    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->error)->not->toBeEmpty()
        ->and($benchmarkResult->output)->toBe('Nothing to do.')
        ->and($benchmarkResult->prompt_tokens)->toBe(100_000)
        ->and((float) $benchmarkResult->cost_usd)->toBe(0.28)
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.28)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and(jobContextActive())->toBeFalse();
});

test('a result whose candidate is missing from the run fails with a readable error', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 3)->create();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->fresh()->error)->toBe('The run has no candidate #3.')
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
    TitleAgent::assertNeverPrompted();
});

test('a candidate whose provider has no API key fails with a readable error', function (): void {
    config()->set('ai.providers.anthropic.key');
    $benchmarkRun = jobRun(['candidates' => [['provider' => 'anthropic', 'model' => 'claude-candidate', 'reasoning' => 'provider_default']]]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->fresh()->error)->toBe('The anthropic provider has no API key configured.')
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and(jobContextActive())->toBeFalse();
    TitleAgent::assertNeverPrompted();
});

test('a candidate on an unknown provider fails with a readable error', function (): void {
    $benchmarkRun = jobRun(['candidates' => [['provider' => 'no-such-provider', 'model' => 'whatever', 'reasoning' => 'bogus-level']]]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->error)->toBe('The no-such-provider provider is not configured.');
    TitleAgent::assertNeverPrompted();
});

test('reaching the cost cap skips the job and every pending result and stops the run', function (): void {
    $benchmarkRun = jobRun(['cost_cap_usd' => 0.5, 'cost_usd' => 0.5]);
    $benchmarkCase = jobTitleCase();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    $otherPending = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    $finished = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->scored(0.9, true)->create();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Skipped)
        ->and($otherPending->fresh()->status)->toBe(BenchmarkResultStatus::Skipped)
        ->and($finished->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::StoppedCostCap)
        ->and($benchmarkRun->fresh()->finished_at)->not->toBeNull();
    TitleAgent::assertNeverPrompted();
});

test('a result that reaches the cost cap stops the run and skips the rest', function (): void {
    $benchmarkRun = jobRun(['cost_cap_usd' => 0.5]);
    $benchmarkCase = jobTitleCase();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    $otherPending = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    jobFakeTitle();
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($otherPending->fresh()->status)->toBe(BenchmarkResultStatus::Skipped)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::StoppedCostCap);
});

test('the job claims its result as running while the candidate runs', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    $statusDuringRun = null;
    TitleAgent::fake(function () use (&$statusDuringRun, $benchmarkResult): StructuredTextResponse {
        $statusDuringRun = $benchmarkResult->fresh()->status;

        return new StructuredTextResponse(['title' => 'Expanse'], '{"title":"Expanse"}', new TextUsage(100_000, 10_000), new Meta('openai', 'gpt-candidate'));
    });
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($statusDuringRun)->toBe(BenchmarkResultStatus::Running)
        ->and($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Ok);
});

test('a result another job is running is not run again', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->running()->create();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Running)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Running);
    TitleAgent::assertNeverPrompted();
});

test('a result whose running job died is claimed again after the stale window', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->running()->create();
    BenchmarkResult::query()->whereKey($benchmarkResult->id)->toBase()->update(['updated_at' => now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 1)]);
    jobFakeTitle();
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
});

test('a running result keeps the run going until it finishes', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkCase = jobTitleCase();
    $first = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->running()->create();
    jobFakeTitle();
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($first->id));

    expect($first->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Running);
});

test('a run cancelled while a result runs keeps that result when its job finishes', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkCase = jobTitleCase();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    $queued = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    TitleAgent::fake(function () use ($benchmarkRun): StructuredTextResponse {
        resolve(BenchmarkRunLauncher::class)->cancel($benchmarkRun);

        return new StructuredTextResponse(['title' => 'Expanse'], '{"title":"Expanse"}', new TextUsage(100_000, 10_000), new Meta('openai', 'gpt-candidate'));
    });
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkResult->fresh()->score)->toBe(0.8)
        ->and($queued->fresh()->status)->toBe(BenchmarkResultStatus::Skipped)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Cancelled);
});

test('a failing candidate counts what it spent towards the run cost and the cap', function (): void {
    $benchmarkRun = jobRun(['cost_cap_usd' => 0.2]);
    $benchmarkCase = jobTitleCase();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    $otherPending = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    TitleAgent::fake(function () use ($benchmarkRun): never {
        jobSpend($benchmarkRun, 100_000, 10_000);

        throw new RuntimeException('The provider dropped the stream.');
    });

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.28)
        ->and($otherPending->fresh()->status)->toBe(BenchmarkResultStatus::Skipped)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::StoppedCostCap);
});

test('a failing judge counts what it spent towards the run cost', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();
    BenchmarkJudgeAgent::fake(function () use ($benchmarkRun): never {
        jobSpend($benchmarkRun, 200_000, 50_000, 'gpt-judge');

        throw new RuntimeException('judge overloaded');
    });

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and((float) $benchmarkResult->fresh()->judge_cost_usd)->toBe(0.0)
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.68);
});

test('the judge is refused when the candidate spend reaches the monthly hard cap', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(0.28);
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();

    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkResult->output)->toBe('Expanse Season Three')
        ->and($benchmarkResult->judge_status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($benchmarkResult->score)->toBeNull()
        ->and($benchmarkResult->score_detail['error'])->toStartWith('Monthly AI hard cap reached')
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('the judge still runs when the candidate spend stays under the monthly hard cap', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(0.5);
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();
    jobFakeJudge(8);

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and($benchmarkResult->fresh()->score)->toBe(0.8);
});

test('a job of a cancelled run does nothing', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Cancelled, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Pending)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Cancelled);
    TitleAgent::assertNeverPrompted();
});

test('a retried job of a cancelled run fails its result whose claim went stale', function (BenchmarkRunStatus $benchmarkRunStatus): void {
    $benchmarkRun = jobRun(['status' => $benchmarkRunStatus, 'finished_at' => now()]);
    $benchmarkCase = jobTitleCase();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->running()->create();
    $finishing = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->running()->create();
    jobStaleClaim($benchmarkResult);
    jobSpend($benchmarkRun, 100_000, 10_000);
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->fresh()->error)->toBe('Stopped before finishing')
        ->and($finishing->fresh()->status)->toBe(BenchmarkResultStatus::Running)
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.28)
        ->and($benchmarkRun->fresh()->status)->toBe($benchmarkRunStatus);
    TitleAgent::assertNeverPrompted();
})->with([BenchmarkRunStatus::Cancelled, BenchmarkRunStatus::StoppedCostCap]);

test('settling a finished run fails every result whose claim went stale', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Cancelled, 'finished_at' => now()]);
    $benchmarkCase = jobTitleCase();
    $stale = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->running()->create();
    jobStaleClaim($stale);
    $queued = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Expanse Season Three',
        'judge_status' => BenchmarkJudgeStatus::Queued,
    ]);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($queued->id, judgeOnly: true));

    expect($queued->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and($stale->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($stale->fresh()->error)->toBe('Stopped before finishing');
});

test('a result that already finished is not run again', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->scored(0.7, true)->create();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->score)->toBe(0.7);
    TitleAgent::assertNeverPrompted();
});

test('a missing result is ignored', function (): void {
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase(999_999));

    TitleAgent::assertNeverPrompted();
});

test('a deleted case fails the result and completes the run', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkCase = jobTitleCase();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    $benchmarkCase->delete();
    jobFakeTitle();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();

    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->error)->toBe('Case was deleted')
        ->and($benchmarkResult->case_name)->toBe($benchmarkCase->name)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
    TitleAgent::assertNeverPrompted();
});

test('a decision case never creates an action request, even with a recorded write fixture', function (): void {
    $proposal = ['type' => 'sonarr_search', 'target_service' => 'sonarr', 'rationale' => 'Missing', 'payload' => ['series_id' => 7]];
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Decision)
        ->withFixtures([['tool' => 'ProposeActionTool', 'arguments' => $proposal, 'result' => '{"status":"queued","action_request_id":12}']])
        ->expectingActions([['tool' => 'ProposeActionTool', 'arguments' => ['type' => 'sonarr_search']]])
        ->create();
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    DecisionAgent::fake([
        new ToolCall(id: 'c1', name: 'ProposeActionTool', arguments: $proposal),
        'Queued a search.',
    ]);

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();

    expect(ActionRequest::count())->toBe(0)
        ->and($benchmarkResult->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkResult->passed)->toBeTrue()
        ->and($benchmarkResult->judge_status)->toBe(BenchmarkJudgeStatus::None)
        ->and($benchmarkResult->intended_actions)->toHaveCount(1)
        ->and($benchmarkResult->tool_calls)->toBe(1)
        ->and(array_column($benchmarkResult->score_detail['tool_log'], 'tool'))->toBe(['ProposeActionTool'])
        ->and(jobContextActive())->toBeFalse();
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('judge-only mode re-scores an unscored result from its stored outcome under the run judge', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now(), 'cost_usd' => 0.28]);
    $toolLog = [
        ['tool' => 'SearchMediaTool', 'arguments' => ['query' => 'expanse'], 'result' => '{"title":"The Expanse"}', 'hit' => true],
        ['tool' => 'DeleteMediaTool', 'arguments' => ['id' => 4], 'result' => '{"error":"advisory_mode_blocks_destructive"}', 'hit' => true, 'write' => true, 'refused' => true],
    ];
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Expanse Season Three',
        'prompt_tokens' => 100_000,
        'completion_tokens' => 10_000,
        'cost_usd' => 0.28,
        'latency_ms' => 420,
        'tool_calls' => 1,
        'judge_status' => BenchmarkJudgeStatus::Queued,
        'score_detail' => ['error' => 'judge overloaded', 'tool_log' => $toolLog],
    ]);
    jobSpend($benchmarkRun, 100_000, 10_000);
    jobFakeTitle();
    jobFakeJudge(9);

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    $benchmarkResult->refresh();

    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkResult->judge_status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and($benchmarkResult->score)->toBe(0.9)
        ->and($benchmarkResult->passed)->toBeTrue()
        ->and($benchmarkResult->score_detail)->toEqual(['score_10' => 9, 'rationale' => 'Concise and accurate', 'tool_log' => $toolLog])
        ->and($benchmarkResult->output)->toBe('Expanse Season Three')
        ->and($benchmarkResult->prompt_tokens)->toBe(100_000)
        ->and((float) $benchmarkResult->cost_usd)->toBe(0.28)
        ->and((float) $benchmarkResult->judge_cost_usd)->toBe(0.4)
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.68)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and(jobContextActive())->toBeFalse();
    TitleAgent::assertNeverPrompted();
    BenchmarkJudgeAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => $agentPrompt->model === 'gpt-judge'
        && str_contains($agentPrompt->prompt, '{"title":"The Expanse"}'));
});

test('judge-only mode leaves an already scored result alone', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->scored(0.6, false)->create(['judge_status' => BenchmarkJudgeStatus::Ok]);
    jobFakeJudge(9);

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    expect($benchmarkResult->fresh()->score)->toBe(0.6);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('the job runs on the ai lane, once, behind the per-run concurrency limit', function (): void {
    Queue::fake();
    $runBenchmarkCase = new RunBenchmarkCase(5);

    dispatch($runBenchmarkCase);

    Queue::assertPushedOn(QueueLane::Ai, RunBenchmarkCase::class);
    expect($runBenchmarkCase->middleware())->toHaveCount(1)
        ->and($runBenchmarkCase->middleware()[0])->toBeInstanceOf(LimitBenchmarkConcurrency::class);
});

test('the concurrency limit releases the job when both run slots are taken', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    Cache::lock(sprintf('benchmark:%d:slot:0', $benchmarkRun->id), 300)->get();
    Cache::lock(sprintf('benchmark:%d:slot:1', $benchmarkRun->id), 300)->get();
    $runBenchmarkCase = new RunBenchmarkCase($benchmarkResult->id)->withFakeQueueInteractions();
    $ran = false;

    new LimitBenchmarkConcurrency($benchmarkResult->id)->handle($runBenchmarkCase, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeFalse();
    $runBenchmarkCase->assertReleased(15);
});

test('a judge-only job waiting for a slot keeps its queued re-judge fresh', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'judge_status' => BenchmarkJudgeStatus::Queued,
    ]);
    jobStaleClaim($benchmarkResult);
    Cache::lock(sprintf('benchmark:%d:slot:0', $benchmarkRun->id), 300)->get();
    Cache::lock(sprintf('benchmark:%d:slot:1', $benchmarkRun->id), 300)->get();
    $runBenchmarkCase = new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true)->withFakeQueueInteractions();

    new LimitBenchmarkConcurrency($benchmarkResult->id)->handle($runBenchmarkCase, fn (): null => null);

    $runBenchmarkCase->assertReleased(15);
    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Queued)
        ->and($benchmarkResult->fresh()->isJudgeStale())->toBeFalse();
});

test('a case job waiting for a slot leaves its pending result alone, which never goes stale', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobStaleClaim($benchmarkResult);
    $updatedAt = $benchmarkResult->fresh()->updated_at;
    Cache::lock(sprintf('benchmark:%d:slot:0', $benchmarkRun->id), 300)->get();
    Cache::lock(sprintf('benchmark:%d:slot:1', $benchmarkRun->id), 300)->get();
    $runBenchmarkCase = new RunBenchmarkCase($benchmarkResult->id)->withFakeQueueInteractions();

    new LimitBenchmarkConcurrency($benchmarkResult->id)->handle($runBenchmarkCase, fn (): null => null);

    $runBenchmarkCase->assertReleased(15);
    expect($benchmarkResult->fresh()->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and($benchmarkResult->fresh()->isRunStale())->toBeFalse();
});

test('the queue gives up on a job only after its result claim went stale', function (): void {
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS)
        ->and(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS)->toBeGreaterThan(280);
});

test('the concurrency limit runs the job on a free slot and frees it afterwards', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    Cache::lock(sprintf('benchmark:%d:slot:0', $benchmarkRun->id), 300)->get();
    Cache::lock(sprintf('benchmark:%d:slot:1', BenchmarkRun::factory()->create()->id), 300)->get();
    $runBenchmarkCase = new RunBenchmarkCase($benchmarkResult->id)->withFakeQueueInteractions();
    $slotHeldDuringRun = null;

    new LimitBenchmarkConcurrency($benchmarkResult->id)->handle($runBenchmarkCase, function () use (&$slotHeldDuringRun, $benchmarkRun): void {
        $slotHeldDuringRun = ! Cache::lock(sprintf('benchmark:%d:slot:1', $benchmarkRun->id), 300)->get();
    });

    expect($slotHeldDuringRun)->toBeTrue()
        ->and(Cache::lock(sprintf('benchmark:%d:slot:1', $benchmarkRun->id), 300)->get())->toBeTrue();
    $runBenchmarkCase->assertNotReleased();
});

test('the concurrency limit frees its slot when the job throws', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    $runBenchmarkCase = new RunBenchmarkCase($benchmarkResult->id)->withFakeQueueInteractions();

    expect(fn (): mixed => new LimitBenchmarkConcurrency($benchmarkResult->id)->handle($runBenchmarkCase, fn (): never => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom')
        ->and(Cache::lock(sprintf('benchmark:%d:slot:0', $benchmarkRun->id), 300)->get())->toBeTrue();
});

test('a job that fails outside the case run fails its pending or running result and settles the run', function (BenchmarkResultStatus $benchmarkResultStatus): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create(['status' => $benchmarkResultStatus]);

    new RunBenchmarkCase($benchmarkResult->id)->failed(new RuntimeException('App\Jobs\RunBenchmarkCase has timed out.'));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->fresh()->error)->toBe('App\Jobs\RunBenchmarkCase has timed out.')
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
})->with([BenchmarkResultStatus::Pending, BenchmarkResultStatus::Running]);

test('a refused monthly hard cap fails the result with the guard message before any agent runs', function (): void {
    $this->mock(AiBudgetGuard::class)->shouldReceive('enforce')->andThrow(new AiBudgetExceededException(10.0, 5.0));
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create();
    jobFakeTitle();
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->fresh()->error)->toBe('Monthly AI hard cap reached (spend $10.00 / cap $5.00).')
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and(jobContextActive())->toBeFalse();
    TitleAgent::assertNeverPrompted();
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('a refused monthly hard cap leaves a re-judge unscored with the guard message', function (): void {
    $this->mock(AiBudgetGuard::class)->shouldReceive('enforce')->andThrow(new AiBudgetExceededException(10.0, 5.0));
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Expanse Season Three',
        'judge_status' => BenchmarkJudgeStatus::Queued,
        'score_detail' => ['error' => 'judge overloaded', 'tool_log' => []],
    ]);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    $benchmarkResult->refresh();

    expect($benchmarkResult->judge_status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($benchmarkResult->score)->toBeNull()
        ->and($benchmarkResult->score_detail)->toEqual(['error' => 'Monthly AI hard cap reached (spend $10.00 / cap $5.00).', 'tool_log' => []])
        ->and(jobContextActive())->toBeFalse();
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('two judge-only jobs racing on one result judge it once and add its cost once', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Expanse Season Three',
        'judge_status' => BenchmarkJudgeStatus::Queued,
        'score_detail' => ['error' => 'judge overloaded', 'tool_log' => []],
    ]);
    $judgeCalls = 0;
    BenchmarkJudgeAgent::fake(function () use (&$judgeCalls, $benchmarkResult): StructuredTextResponse {
        $judgeCalls++;

        if ($judgeCalls === 1) {
            dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));
        }

        return new StructuredTextResponse(['score' => 9, 'rationale' => 'Good'], '{"score":9,"rationale":"Good"}', new TextUsage(200_000, 50_000), new Meta('openai', 'gpt-judge'));
    });

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    expect($judgeCalls)->toBe(1)
        ->and($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and((float) $benchmarkResult->fresh()->judge_cost_usd)->toBe(0.4)
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.4);
});

test('a judge-only job that dies queued or mid-judge hands the result back to re-judging', function (BenchmarkJudgeStatus $benchmarkJudgeStatus): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'judge_status' => $benchmarkJudgeStatus,
    ]);

    new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true)->failed(new RuntimeException('timed out'));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Ok);
})->with([BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending]);

test('a judge-only job that times out settles the run cost its judge already spent', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'judge_status' => BenchmarkJudgeStatus::Pending,
    ]);
    jobSpend($benchmarkRun, 200_000, 50_000, 'gpt-judge');

    new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true)->failed(new RuntimeException('timed out'));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and((float) $benchmarkRun->fresh()->cost_usd)->toBe(0.4);
});

test('a judge-only job takes over a re-judge whose claim or dispatch went stale', function (BenchmarkJudgeStatus $benchmarkJudgeStatus): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Expanse Season Three',
        'judge_status' => $benchmarkJudgeStatus,
    ]);
    jobStaleClaim($benchmarkResult);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and($benchmarkResult->fresh()->score)->toBe(0.8);
})->with([BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending]);

test('a judge-only job leaves a re-judge another job is judging alone', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'judge_status' => BenchmarkJudgeStatus::Pending,
    ]);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Pending);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('a judge-only job only claims a result queued for re-judging', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobTitleCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'judge_status' => BenchmarkJudgeStatus::Failed,
    ]);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Failed);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('a judge-only job whose case was deleted hands the result back to re-judging', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $benchmarkCase = jobTitleCase();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'judge_status' => BenchmarkJudgeStatus::Queued,
        'score_detail' => ['error' => 'judge overloaded', 'tool_log' => []],
    ]);
    $benchmarkCase->delete();
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    expect($benchmarkResult->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Failed)
        ->and($benchmarkResult->fresh()->score_detail)->toEqual(['error' => 'Case was deleted', 'tool_log' => []]);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('the job is unique per result and mode', function (): void {
    expect(new RunBenchmarkCase(7))->toBeInstanceOf(ShouldBeUnique::class)
        ->and(new RunBenchmarkCase(7)->uniqueId())->toBe('benchmark-result:7:run')
        ->and(new RunBenchmarkCase(7, judgeOnly: true)->uniqueId())->toBe('benchmark-result:7:judge');
});

test('the job waits for a slot without burning tries but fails on its first exception or timeout', function (): void {
    $this->freezeTime();
    $runBenchmarkCase = new RunBenchmarkCase(7);
    $syncQueue = new SyncQueue;
    $syncQueue->setContainer(app());

    $payload = json_decode((fn (): string => $this->createPayload($runBenchmarkCase, 'ai'))->call($syncQueue), true);

    expect($payload['maxTries'])->toBe(0)
        ->and($payload['maxExceptions'])->toBe(1)
        ->and($payload['failOnTimeout'])->toBeTrue()
        ->and($payload['timeout'])->toBe(280)
        ->and($payload['retryUntil'])->toBe(now()->addHours(12)->getTimestamp())
        ->and(new ReflectionClass(RunBenchmarkCase::class)->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)
        ->toBeGreaterThan(12 * 3600);
});

test('history user turns carry their attachments to the candidate', function (): void {
    Storage::fake('local');
    $benchmarkRun = jobRun();
    $chatCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'What does this error mean?',
        'history' => [['role' => 'user', 'content' => 'Here is the log.'], ['role' => 'assistant', 'content' => 'Thanks.']],
    ]]);
    BenchmarkCaseAttachment::factory()->for($chatCase)->text()->forTurn(0)->create();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $chatCase, 0)->create();
    $seen = [];
    MediaAgent::fake([function () use (&$seen): string {
        $seen = array_map(
            static fn (Message $message): array => $message instanceof UserMessage ? $message->attachments->map(static fn (File $file): ?string => $file->name())->all() : [],
            resolve(BenchmarkRunContext::class)->history(),
        );

        return 'A stalled import.';
    }]);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($seen)->toBe([['download.log'], []]);
});

test('a candidate that cannot read images fails the result with its provider error and the run still completes', function (): void {
    Storage::fake('local');
    $benchmarkRun = jobRun();
    $chatCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => ['prompt' => 'What is this?']]);
    BenchmarkCaseAttachment::factory()->for($chatCase)->image()->create();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $chatCase, 0)->create();
    MediaAgent::fake(fn (): never => throw new RuntimeException('This model does not support image input.'));
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();
    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->error)->toBe('This model does not support image input.')
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

function jobScriptedCase(): BenchmarkCase
{
    return BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'Then add it.',
        'conversation' => 'scripted',
        'history' => [
            ['role' => 'user', 'content' => 'Is Dune in the library?'],
            ['role' => 'assistant', 'content' => 'RECORDED REPLY'],
            ['role' => 'user', 'content' => 'And the sequel?'],
        ],
    ]]);
}

test('a scripted result stores the candidate transcript next to the tool log', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobScriptedCase(), 0)->create();
    MediaAgent::fake(['Yes.', 'Missing.', 'Queued.']);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();
    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkResult->output)->toBe('Queued.')
        ->and($benchmarkResult->score_detail['tool_log'])->toBe([])
        ->and($benchmarkResult->score_detail['transcript'])->toHaveCount(6)
        ->and($benchmarkResult->score_detail['transcript'][1])->toBe(['role' => 'assistant', 'content' => 'Yes.', 'attachments' => []]);
});

test('a scripted result whose middle turn fails keeps the earlier turns and names the turn', function (): void {
    $benchmarkRun = jobRun();
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobScriptedCase(), 0)->create();
    MediaAgent::fake([
        fn (): TextResponse => new TextResponse('Yes.', new TextUsage(100_000, 10_000), new Meta('openai', 'gpt-candidate')),
        fn (): never => throw new RuntimeException('provider down'),
    ]);
    jobFakeJudge();

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id));

    $benchmarkResult->refresh();
    expect($benchmarkResult->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->error)->toBe('Turn 2 of 3 failed: provider down')
        ->and($benchmarkResult->output)->toBe('Yes.')
        ->and($benchmarkResult->prompt_tokens)->toBe(100_000)
        ->and((float) $benchmarkResult->cost_usd)->toBe(0.28)
        ->and($benchmarkResult->score_detail)->toBe(['tool_log' => [], 'transcript' => [
            ['role' => 'user', 'content' => 'Is Dune in the library?', 'attachments' => []],
            ['role' => 'assistant', 'content' => 'Yes.', 'attachments' => []],
            ['role' => 'user', 'content' => 'And the sequel?', 'attachments' => []],
        ]])
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
    BenchmarkJudgeAgent::assertNeverPrompted();
});

test('a re-judge keeps the stored transcript, dropping malformed entries', function (): void {
    $benchmarkRun = jobRun(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
    $kept = [
        ['role' => 'user', 'content' => 'Is Dune in the library?', 'attachments' => []],
        ['role' => 'assistant', 'content' => 'Yes.', 'attachments' => []],
    ];
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, jobScriptedCase(), 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Yes.',
        'judge_status' => BenchmarkJudgeStatus::Queued,
        'score_detail' => ['error' => 'judge overloaded', 'tool_log' => [], 'transcript' => [...$kept, 'garbage', ['role' => 'system', 'content' => 'x']]],
    ]);
    jobFakeJudge(9);

    dispatch_sync(new RunBenchmarkCase($benchmarkResult->id, judgeOnly: true));

    $benchmarkResult->refresh();
    expect($benchmarkResult->judge_status)->toBe(BenchmarkJudgeStatus::Ok)
        ->and($benchmarkResult->score_detail['transcript'])->toBe($kept);
});
