<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\FixtureBook;
use App\Ai\ResolvedSelection;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkCaseRunner;
use App\Services\Benchmark\CaseOutcome;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.anthropic.key', 'sk-test');
});

/**
 * @param  list<Message>  $history
 */
function runnerStartContext(BenchmarkCase $benchmarkCase, array $history = []): BenchmarkRunContext
{
    $benchmarkRunContext = resolve(BenchmarkRunContext::class);
    $benchmarkRunContext->start(
        BenchmarkRun::factory()->create()->id,
        $benchmarkCase->task,
        new ResolvedSelection('openai', 'gpt-candidate', AiReasoningLevel::ProviderDefault),
        null,
        new FixtureBook($benchmarkCase->fixtures),
        $history,
    );

    return $benchmarkRunContext;
}

test('a title case outputs the structured title', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['input' => ['prompt' => 'What is the third season of The Expanse about?']]);
    runnerStartContext($benchmarkCase);
    TitleAgent::fake([['title' => 'Expanse Season Three']]);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect($caseOutcome)->toBeInstanceOf(CaseOutcome::class)
        ->and($caseOutcome->output)->toBe('Expanse Season Three')
        ->and($caseOutcome->intendedActions)->toBe([])
        ->and($caseOutcome->toolCalls)->toBe(0)
        ->and($caseOutcome->latencyMs)->toBeGreaterThanOrEqual(0)
        ->and($caseOutcome->invocationId)->toBeString();
    TitleAgent::assertPrompted('What is the third season of The Expanse about?');
});

test('a decision case outputs the text and intends nothing when the agent proposes nothing', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Decision)->create();
    runnerStartContext($benchmarkCase);
    DecisionAgent::fake(['No action needed.']);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect($caseOutcome->output)->toBe('No action needed.')
        ->and($caseOutcome->intendedActions)->toBe([])
        ->and($caseOutcome->toolLog)->toBe([]);
});

test('the outcome carries the response usage', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Decision)->create();
    runnerStartContext($benchmarkCase);
    DecisionAgent::fake(fn (): TextResponse => new TextResponse('Nothing to do.', new TextUsage(1200, 340), new Meta('openai', 'gpt-candidate')));

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect([$caseOutcome->promptTokens, $caseOutcome->completionTokens])->toBe([1200, 340]);
});

test('a decision case records the tool calls, misses and intended actions of the run', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Decision)->withFixtures([
        ['tool' => 'GetServiceStatusTool', 'arguments' => [], 'result' => '{"sonarr":"ok"}'],
    ])->create();
    runnerStartContext($benchmarkCase);
    DecisionAgent::fake([
        new ToolCall(id: 'c1', name: 'GetServiceStatusTool', arguments: []),
        new ToolCall(id: 'c2', name: 'QueryActivityTool', arguments: ['limit' => 5]),
        new ToolCall(id: 'c3', name: 'ProposeActionTool', arguments: ['type' => 'sonarr_search', 'target_service' => 'sonarr', 'rationale' => 'Missing', 'payload' => ['series_id' => 7]]),
        'Queued a search.',
    ]);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect($caseOutcome->output)->toBe('Queued a search.')
        ->and($caseOutcome->toolCalls)->toBe(3)
        ->and($caseOutcome->toolMisses)->toBe(1)
        ->and(array_column($caseOutcome->toolLog, 'tool'))->toBe(['GetServiceStatusTool', 'QueryActivityTool', 'ProposeActionTool'])
        ->and($caseOutcome->intendedActions)->toHaveCount(1)
        ->and($caseOutcome->intendedActions[0]['tool'])->toBe('ProposeActionTool');
});

test('a chat case replays the case history and outputs the text', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => ['prompt' => 'And the second one?']]);
    $history = [new UserMessage('Is Dune in the library?'), new AssistantMessage('Yes, Dune (2021) is there.')];
    runnerStartContext($benchmarkCase, $history);
    MediaAgent::fake(['Dune: Part Two is not in the library.']);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect($caseOutcome->output)->toBe('Dune: Part Two is not in the library.');
    MediaAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => $agentPrompt->prompt === 'And the second one?'
        && $agentPrompt->agent instanceof MediaAgent
        && [...$agentPrompt->agent->messages()] === $history);
});

test('a structured sub-agent case outputs the JSON of the structured response', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::FileInspector)->judged()->create(['input' => ['prompt' => 'Inspect Dune subtitles']]);
    runnerStartContext($benchmarkCase);
    $structured = ['summary' => 'English track missing', 'ambiguous' => false];
    MediaFileInspectorAgent::fake([$structured]);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect(json_decode($caseOutcome->output, true))->toBe($structured);
});

test('the price updater runs scoped to the case providers on the app web fetch tool as a dry run', function (): void {
    config()->set('mediamanager.ai.price_fetcher_provider_webfetch', true);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::PriceUpdater)->create([
        'input' => ['prompt' => 'Verify anthropic prices', 'providers' => ['anthropic']],
        'scoring' => ['type' => 'prices', 'tolerance_percent' => 1, 'expected' => []],
    ]);
    runnerStartContext($benchmarkCase);
    $providerWebFetchDuringRun = null;
    PriceFetcherAgent::fake([
        new ToolCall(id: 'c1', name: 'UpsertModelPriceTool', arguments: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3, 'output_per_mtok' => 15]),
        function () use (&$providerWebFetchDuringRun): string {
            $providerWebFetchDuringRun = config('mediamanager.ai.price_fetcher_provider_webfetch');

            return 'Upserted 1 row for anthropic.';
        },
    ]);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect($caseOutcome->output)->toBe('Upserted 1 row for anthropic.')
        ->and($caseOutcome->intendedActions)->toHaveCount(1)
        ->and($caseOutcome->intendedActions[0]['tool'])->toBe('UpsertModelPriceTool')
        ->and($providerWebFetchDuringRun)->toBeFalse()
        ->and(config('mediamanager.ai.price_fetcher_provider_webfetch'))->toBeTrue();
    PriceFetcherAgent::assertPrompted(function (AgentPrompt $agentPrompt): bool {
        $instructions = (string) $agentPrompt->agent->instructions();

        return $agentPrompt->prompt === 'Verify anthropic prices'
            && str_contains($instructions, '- anthropic:')
            && ! str_contains($instructions, '- openai:');
    });
});

test('a provider failure propagates and the web fetch flag is restored', function (): void {
    config()->set('mediamanager.ai.price_fetcher_provider_webfetch', true);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::PriceUpdater)->create([
        'input' => ['prompt' => 'Verify openai prices', 'providers' => ['openai']],
    ]);
    runnerStartContext($benchmarkCase);
    PriceFetcherAgent::fake(fn (): never => throw new RuntimeException('provider fell over'));

    expect(fn (): CaseOutcome => resolve(BenchmarkCaseRunner::class)->run($benchmarkCase))->toThrow(RuntimeException::class, 'provider fell over')
        ->and(config('mediamanager.ai.price_fetcher_provider_webfetch'))->toBeTrue();
});

test('a faked provider exception on a title case propagates', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    runnerStartContext($benchmarkCase);
    TitleAgent::fake(fn (): never => throw new RuntimeException('rate limited'));

    expect(fn (): CaseOutcome => resolve(BenchmarkCaseRunner::class)->run($benchmarkCase))->toThrow(RuntimeException::class, 'rate limited');
});

test('the runner refuses to run without an active benchmark context', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    TitleAgent::fake([['title' => 'Never Runs']]);

    expect(fn (): CaseOutcome => resolve(BenchmarkCaseRunner::class)->run($benchmarkCase))->toThrow(LogicException::class);
    TitleAgent::assertNeverPrompted();
});

test('the runner refuses a case whose task differs from the active context', function (): void {
    $titleCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    runnerStartContext(BenchmarkCase::factory()->forTask(AiTask::Decision)->create());
    TitleAgent::fake([['title' => 'Never Runs']]);

    expect(fn (): CaseOutcome => resolve(BenchmarkCaseRunner::class)->run($titleCase))->toThrow(LogicException::class);
    TitleAgent::assertNeverPrompted();
});
