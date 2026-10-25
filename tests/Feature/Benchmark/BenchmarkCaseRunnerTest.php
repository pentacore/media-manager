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
use App\Models\BenchmarkCaseAttachment;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkCaseRunner;
use App\Services\Benchmark\CaseOutcome;
use App\Services\Benchmark\ScriptedTurnFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\StoredImage;
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

test('a chat case sends the prompt attachments with the prompt and never a history turn one', function (): void {
    Storage::fake('local');
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'What does this error mean?',
        'history' => [['role' => 'user', 'content' => 'Here is the log.'], ['role' => 'assistant', 'content' => 'Thanks.']],
    ]]);
    BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->forTurn(0)->create();
    BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    runnerStartContext($benchmarkCase);
    $sent = null;
    MediaAgent::fake([function (string $prompt, Collection $attachments) use (&$sent): string {
        $sent = $attachments->map(static fn (File $file): array => [$file::class, $file->name()])->all();

        return 'A stalled import.';
    }]);

    resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect($sent)->toBe([[StoredImage::class, 'screenshot.png']]);
});

test('a chat case whose attachment file is gone fails before the candidate runs', function (): void {
    Storage::fake('local');
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => ['prompt' => 'What is this?']]);
    $attachment = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    Storage::disk('local')->delete($attachment->path);
    runnerStartContext($benchmarkCase);
    MediaAgent::fake(['never']);

    expect(fn () => resolve(BenchmarkCaseRunner::class)->run($benchmarkCase))
        ->toThrow(RuntimeException::class, 'The attachment screenshot.png (final prompt) is no longer stored; attach it to the case again.');
    MediaAgent::assertNeverPrompted();
});

function runnerScriptedCase(): BenchmarkCase
{
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'Then add it.',
        'conversation' => 'scripted',
        'history' => [
            ['role' => 'user', 'content' => 'Is Dune in the library?'],
            ['role' => 'assistant', 'content' => 'RECORDED REPLY'],
            ['role' => 'user', 'content' => 'And the sequel?'],
        ],
    ]]);
    BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->forTurn(0)->create();

    return $benchmarkCase;
}

/**
 * One candidate reply, recording the prompt, attachments and history its turn saw.
 *
 * @param  list<array<string, mixed>>  $turns
 */
function runnerScriptedReply(string $text, int $promptTokens, array &$turns): Closure
{
    return function (string $prompt, Collection $attachments) use ($text, $promptTokens, &$turns): TextResponse {
        $turns[] = [
            'prompt' => $prompt,
            'attachments' => $attachments->map(static fn (File $file): ?string => $file->name())->all(),
            'history' => array_map(static fn (Message $message): array => [
                $message->role->value,
                $message->content,
                $message instanceof UserMessage ? $message->attachments->map(static fn (File $file): ?string => $file->name())->all() : [],
            ], resolve(BenchmarkRunContext::class)->history()),
        ];

        return new TextResponse($text, new TextUsage($promptTokens, intdiv($promptTokens, 10)), new Meta('openai', 'gpt-candidate'));
    };
}

test('a scripted case answers every user turn in order on its own earlier replies', function (): void {
    Storage::fake('local');
    $benchmarkCase = runnerScriptedCase();
    runnerStartContext($benchmarkCase, [new UserMessage('stale starter history')]);
    $turns = [];
    MediaAgent::fake([
        runnerScriptedReply('Yes, Dune is there.', 100, $turns),
        runnerScriptedReply('Dune: Part Two is missing.', 200, $turns),
        runnerScriptedReply('Queued Dune: Part Two.', 300, $turns),
    ]);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect(array_column($turns, 'prompt'))->toBe(['Is Dune in the library?', 'And the sequel?', 'Then add it.'])
        ->and($turns[0]['attachments'])->toBe(['download.log'])
        ->and($turns[0]['history'])->toBe([])
        ->and($turns[1]['history'])->toBe([
            ['user', 'Is Dune in the library?', ['download.log']],
            ['assistant', 'Yes, Dune is there.', []],
        ])
        ->and($turns[2]['history'])->toHaveCount(4)
        ->and($caseOutcome->output)->toBe('Queued Dune: Part Two.')
        ->and($caseOutcome->promptTokens)->toBe(600)
        ->and($caseOutcome->completionTokens)->toBe(60)
        ->and($caseOutcome->transcript)->toBe([
            ['role' => 'user', 'content' => 'Is Dune in the library?', 'attachments' => ['download.log']],
            ['role' => 'assistant', 'content' => 'Yes, Dune is there.', 'attachments' => []],
            ['role' => 'user', 'content' => 'And the sequel?', 'attachments' => []],
            ['role' => 'assistant', 'content' => 'Dune: Part Two is missing.', 'attachments' => []],
            ['role' => 'user', 'content' => 'Then add it.', 'attachments' => []],
            ['role' => 'assistant', 'content' => 'Queued Dune: Part Two.', 'attachments' => []],
        ]);
});

test('scripted turns share one fixture book, so the tool log runs across turns in order', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->withFixtures([
        ['tool' => 'GetServiceStatusTool', 'arguments' => [], 'result' => '{"sonarr":"ok"}'],
    ])->create(['input' => ['prompt' => 'And Radarr?', 'conversation' => 'scripted', 'history' => [['role' => 'user', 'content' => 'Is Sonarr up?']]]]);
    runnerStartContext($benchmarkCase);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'GetServiceStatusTool', arguments: []),
        'Sonarr is up.',
        new ToolCall(id: 'c2', name: 'QueryActivityTool', arguments: ['limit' => 5]),
        'No Radarr activity.',
    ]);

    $caseOutcome = resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);

    expect(array_column($caseOutcome->toolLog, 'tool'))->toBe(['GetServiceStatusTool', 'QueryActivityTool'])
        ->and($caseOutcome->toolCalls)->toBe(2)
        ->and($caseOutcome->toolMisses)->toBe(1)
        ->and($caseOutcome->output)->toBe('No Radarr activity.');
});

test('a scripted turn that throws names its turn and keeps what the earlier turns produced', function (): void {
    Storage::fake('local');
    $benchmarkCase = runnerScriptedCase();
    runnerStartContext($benchmarkCase);
    $turns = [];
    MediaAgent::fake([
        runnerScriptedReply('Yes, Dune is there.', 100, $turns),
        fn (): never => throw new RuntimeException('provider down'),
    ]);

    $thrown = null;

    try {
        resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);
    } catch (ScriptedTurnFailed $scriptedTurnFailed) {
        $thrown = $scriptedTurnFailed;
    }

    expect($thrown)->toBeInstanceOf(ScriptedTurnFailed::class)
        ->and($thrown->getMessage())->toBe('Turn 2 of 3 failed: provider down')
        ->and($thrown->getPrevious()?->getMessage())->toBe('provider down')
        ->and($thrown->partialOutcome->output)->toBe('Yes, Dune is there.')
        ->and($thrown->partialOutcome->promptTokens)->toBe(100)
        ->and($thrown->partialOutcome->transcript)->toBe([
            ['role' => 'user', 'content' => 'Is Dune in the library?', 'attachments' => ['download.log']],
            ['role' => 'assistant', 'content' => 'Yes, Dune is there.', 'attachments' => []],
            ['role' => 'user', 'content' => 'And the sequel?', 'attachments' => []],
        ]);
});

test('a scripted case stops before a turn once it used its time budget', function (): void {
    Storage::fake('local');
    $benchmarkCase = runnerScriptedCase();
    runnerStartContext($benchmarkCase);
    $turns = [];
    $slow = runnerScriptedReply('Yes, Dune is there.', 100, $turns);
    MediaAgent::fake([
        function (string $prompt, Collection $attachments) use ($slow, &$turns): TextResponse {
            $response = $slow($prompt, $attachments);
            $this->travel(201)->seconds();

            return $response;
        },
        runnerScriptedReply('never', 100, $turns),
    ]);

    $thrown = null;

    try {
        resolve(BenchmarkCaseRunner::class)->run($benchmarkCase);
    } catch (ScriptedTurnFailed $scriptedTurnFailed) {
        $thrown = $scriptedTurnFailed;
    }

    expect($thrown)->toBeInstanceOf(ScriptedTurnFailed::class)
        ->and($thrown->getMessage())->toBe('Turn 2 of 3 not started: the case used its 200 s time budget.')
        ->and($turns)->toHaveCount(1)
        ->and($thrown->partialOutcome->transcript)->toBe([
            ['role' => 'user', 'content' => 'Is Dune in the library?', 'attachments' => ['download.log']],
            ['role' => 'assistant', 'content' => 'Yes, Dune is there.', 'attachments' => []],
        ]);
});

test('a context chat case keeps an empty transcript', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => ['prompt' => 'Hi']]);
    runnerStartContext($benchmarkCase);
    MediaAgent::fake(['Hello.']);

    expect(resolve(BenchmarkCaseRunner::class)->run($benchmarkCase)->transcript)->toBe([]);
});
