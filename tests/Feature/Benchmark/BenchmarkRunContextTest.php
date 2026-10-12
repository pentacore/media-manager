<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\FixtureBook;
use App\Ai\ChatTurnContext;
use App\Ai\ModelSelection;
use App\Ai\ResolvedSelection;
use App\Ai\TaskModelResolver;
use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkRun;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.anthropic.key', 'sk-test');
    config()->set('mediamanager.ai.model', 'gpt-config-chat');
    config()->set('mediamanager.ai.title_model', 'gpt-config-title');
    config()->set('mediamanager.ai.advisor_reasoning_level');
});

function benchmarkContextResolver(): TaskModelResolver
{
    $taskModelResolver = resolve(TaskModelResolver::class);
    $taskModelResolver->flush();

    return $taskModelResolver;
}

function benchmarkContextCandidate(): ResolvedSelection
{
    return new ResolvedSelection('anthropic', 'claude-candidate', AiReasoningLevel::High);
}

/**
 * @param  list<Message>  $history
 */
function benchmarkContextStart(AiTask $aiTask = AiTask::Chat, ?ResolvedSelection $judge = null, array $history = [], ?AiMode $aiMode = null): BenchmarkRunContext
{
    $benchmarkRunContext = resolve(BenchmarkRunContext::class);
    $benchmarkRunContext->start(BenchmarkRun::factory()->create()->id, $aiTask, benchmarkContextCandidate(), $judge, new FixtureBook([]), $history, $aiMode);

    return $benchmarkRunContext;
}

test('an inactive context changes nothing and tiers still apply', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-tier-chat')->create();

    $benchmarkRunContext = resolve(BenchmarkRunContext::class);
    $resolvedSelection = benchmarkContextResolver()->resolve(AiTask::Chat);

    expect($benchmarkRunContext->active())->toBeFalse()
        ->and($benchmarkRunContext->runId())->toBeNull()
        ->and($benchmarkRunContext->task())->toBeNull()
        ->and($benchmarkRunContext->selectionFor(AiTask::Chat))->toBeNull()
        ->and([$resolvedSelection->provider, $resolvedSelection->model])->toBe(['openai', 'gpt-tier-chat'])
        ->and(fn (): FixtureBook => $benchmarkRunContext->fixtures())->toThrow(LogicException::class);
});

test('the case task resolves to the candidate despite tiers and a conversation override', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-tier-one')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-tier-two')->create();
    resolve(ChatTurnContext::class)->apply('openai', 'gpt-override', AiReasoningLevel::Low);
    benchmarkContextStart();

    $resolvedSelection = benchmarkContextResolver()->resolve(AiTask::Chat);

    expect([$resolvedSelection->provider, $resolvedSelection->model, $resolvedSelection->reasoning, $resolvedSelection->tier])
        ->toBe(['anthropic', 'claude-candidate', AiReasoningLevel::High, null]);
});

test('the judge task resolves to the run judge and other tasks resolve normally', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-tier-title')->create();
    benchmarkContextStart(AiTask::Decision, new ResolvedSelection('openai', 'gpt-judge', AiReasoningLevel::Medium));

    $resolvedSelection = benchmarkContextResolver()->resolve(AiTask::BenchmarkJudge);
    $title = benchmarkContextResolver()->resolve(AiTask::Title);

    expect([$resolvedSelection->provider, $resolvedSelection->model, $resolvedSelection->reasoning])->toBe(['openai', 'gpt-judge', AiReasoningLevel::Medium])
        ->and([$title->provider, $title->model])->toBe(['openai', 'gpt-tier-title']);
});

test('without a judge selection the judge task resolves normally', function (): void {
    benchmarkContextStart(AiTask::Decision);

    $resolvedSelection = benchmarkContextResolver()->resolve(AiTask::BenchmarkJudge);

    expect([$resolvedSelection->provider, $resolvedSelection->model])->toBe(['openai', 'gpt-config-chat']);
});

test('the provider chain has no failover while a run is active', function (): void {
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Gemini->value])->create();
    benchmarkContextResolver();
    $modelSelection = new ModelSelection('anthropic', 'claude-candidate');

    $inactiveChain = resolve(AiSettings::class)->providerChainFor($modelSelection);
    benchmarkContextStart();
    $activeChain = resolve(AiSettings::class)->providerChainFor($modelSelection);

    expect($inactiveChain)->toHaveKey('gemini')
        ->and($activeChain)->toBe(['anthropic' => 'claude-candidate']);
});

test('usage rows written during a run carry the run id', function (): void {
    $benchmarkRunContext = benchmarkContextStart(AiTask::Title);
    TitleAgent::fake([['title' => 'A Benchmark Title']]);

    (new TitleAgent)->prompt('Hello there');

    $usage = AiUsageRecord::query()->where('agent_class', TitleAgent::class)->latest('id')->first();
    expect($usage?->benchmark_run_id)->toBe($benchmarkRunContext->runId())
        ->and($usage?->is_benchmark)->toBeTrue();
});

test('failed run rows written during a run carry the run id', function (): void {
    $benchmarkRunContext = benchmarkContextStart(AiTask::Title);
    TitleAgent::fake(fn (): never => throw new RuntimeException('provider fell over'));

    expect(fn (): mixed => (new TitleAgent)->prompt('Hello there'))->toThrow(RuntimeException::class);

    $usage = AiUsageRecord::query()->where('agent_class', TitleAgent::class)->latest('id')->first();
    expect($usage?->status)->toBe('failed')
        ->and($usage?->benchmark_run_id)->toBe($benchmarkRunContext->runId())
        ->and($usage?->is_benchmark)->toBeTrue();
});

test('usage rows outside a run carry no run id', function (): void {
    TitleAgent::fake([['title' => 'A Normal Title']]);

    (new TitleAgent)->prompt('Hello there');

    $usage = AiUsageRecord::query()->where('agent_class', TitleAgent::class)->latest('id')->first();

    expect($usage?->benchmark_run_id)->toBeNull()
        ->and($usage?->is_benchmark)->toBeFalse();
});

test('the context exposes the case fixtures, history and mode', function (): void {
    $history = [new UserMessage('Earlier question'), new AssistantMessage('Earlier answer')];
    $benchmarkRunContext = benchmarkContextStart(AiTask::Chat, null, $history, AiMode::Advisory);

    expect($benchmarkRunContext->task())->toBe(AiTask::Chat)
        ->and($benchmarkRunContext->fixtures())->toBeInstanceOf(FixtureBook::class)
        ->and($benchmarkRunContext->history())->toBe($history)
        ->and($benchmarkRunContext->mode())->toBe(AiMode::Advisory);
});

test('the media agent replays the case history while a run is active', function (): void {
    $history = [new UserMessage('Earlier question'), new AssistantMessage('Earlier answer')];
    benchmarkContextStart(AiTask::Chat, null, $history);

    expect([...(new MediaAgent)->messages()])->toBe($history);
});

test('stop restores normal resolution', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-tier-chat')->create();
    $benchmarkRunContext = benchmarkContextStart();

    $benchmarkRunContext->stop();

    $resolvedSelection = benchmarkContextResolver()->resolve(AiTask::Chat);

    expect($benchmarkRunContext->active())->toBeFalse()
        ->and($benchmarkRunContext->runId())->toBeNull()
        ->and($benchmarkRunContext->history())->toBe([])
        ->and([$resolvedSelection->provider, $resolvedSelection->model])->toBe(['openai', 'gpt-tier-chat']);
});
