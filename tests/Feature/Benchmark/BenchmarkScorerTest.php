<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\FixtureBook;
use App\Ai\ResolvedSelection;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Models\AiModelPrice;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkScorer;
use App\Services\Benchmark\CaseOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\File;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.anthropic.key', 'sk-test');
});

/**
 * @param  list<array{tool: string, arguments: array<string, mixed>}>  $intendedActions
 * @param  list<array<string, mixed>>  $toolLog
 */
function scorerOutcome(array $intendedActions = [], string $output = '', array $toolLog = []): CaseOutcome
{
    return new CaseOutcome(
        output: $output,
        intendedActions: $intendedActions,
        toolLog: $toolLog,
        toolCalls: count($toolLog),
        toolMisses: 0,
        promptTokens: 100,
        completionTokens: 20,
        latencyMs: 15,
        invocationId: 'inv-1',
    );
}

function scorerStartContext(AiTask $aiTask): void
{
    resolve(BenchmarkRunContext::class)->start(
        BenchmarkRun::factory()->create()->id,
        $aiTask,
        new ResolvedSelection('anthropic', 'claude-candidate-secret', AiReasoningLevel::ProviderDefault),
        new ResolvedSelection('openai', 'gpt-judge', AiReasoningLevel::ProviderDefault),
        new FixtureBook([]),
    );
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function scorerPriceCase(array $rows, float $tolerance = 1): BenchmarkCase
{
    return BenchmarkCase::factory()->forTask(AiTask::PriceUpdater)->create([
        'input' => ['prompt' => 'Verify prices', 'providers' => ['anthropic']],
        'scoring' => ['type' => 'prices', 'tolerance_percent' => $tolerance, 'expected' => $rows],
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{tool: string, arguments: array<string, mixed>}
 */
function scorerUpsert(array $arguments): array
{
    return ['tool' => 'UpsertModelPriceTool', 'arguments' => $arguments];
}

test('an exact action match passes with a full score', function (): void {
    $action = ['tool' => 'ProposeActionTool', 'arguments' => ['type' => 'sonarr_search', 'payload' => ['series_id' => 7]]];
    $benchmarkCase = BenchmarkCase::factory()->expectingActions([$action])->create();

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([$action]));

    expect($scores['score'])->toBe(1.0)
        ->and($scores['passed'])->toBeTrue()
        ->and($scores['judge_status'])->toBe(BenchmarkJudgeStatus::None)
        ->and($scores['judge_cost_usd'])->toBe(0.0)
        ->and($scores['detail']['matched'])->toHaveCount(1)
        ->and($scores['detail']['missing'])->toBe([])
        ->and($scores['detail']['extra'])->toBe([]);
});

test('a missing expected action halves recall and fails', function (): void {
    $search = ['tool' => 'ProposeActionTool', 'arguments' => ['type' => 'sonarr_search']];
    $remove = ['tool' => 'RemoveStuckDownloadTool', 'arguments' => ['download_id' => 'abc']];
    $benchmarkCase = BenchmarkCase::factory()->expectingActions([$search, $remove])->create();

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([$search]));

    expect($scores['passed'])->toBeFalse()
        ->and($scores['detail']['recall'])->toBe(0.5)
        ->and($scores['detail']['precision'])->toBe(1.0)
        ->and($scores['score'])->toEqualWithDelta(2 / 3, 0.0001)
        ->and($scores['detail']['missing'])->toBe([$remove])
        ->and($scores['detail']['extra'])->toBe([]);
});

test('an extra intended action fails', function (): void {
    $search = ['tool' => 'ProposeActionTool', 'arguments' => ['type' => 'sonarr_search']];
    $remove = ['tool' => 'RemoveStuckDownloadTool', 'arguments' => ['download_id' => 'abc']];
    $benchmarkCase = BenchmarkCase::factory()->expectingActions([$search])->create();

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([$search, $remove]));

    expect($scores['passed'])->toBeFalse()
        ->and($scores['detail']['precision'])->toBe(0.5)
        ->and($scores['detail']['extra'])->toBe([$remove]);
});

test('expecting nothing and intending nothing passes', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->expectingActions([])->create();

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome());

    expect($scores['score'])->toBe(1.0)
        ->and($scores['passed'])->toBeTrue();
});

test('one empty side scores zero', function (array $expected, array $intended): void {
    $benchmarkCase = BenchmarkCase::factory()->expectingActions($expected)->create();

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome($intended));

    expect($scores['score'])->toBe(0.0)
        ->and($scores['passed'])->toBeFalse();
})->with([
    'nothing intended' => [[['tool' => 'ProposeActionTool', 'arguments' => []]], []],
    'nothing expected' => [[], [['tool' => 'ProposeActionTool', 'arguments' => []]]],
]);

test('an expected argument subset matches despite key order and case differences', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->expectingActions([
        ['tool' => 'ProposeActionTool', 'arguments' => ['payload' => ['title' => 'the expanse'], 'type' => 'SONARR_SEARCH']],
    ])->create();

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([
        ['tool' => 'ProposeActionTool', 'arguments' => ['type' => 'sonarr_search', 'rationale' => 'Missing episodes', 'payload' => ['series_id' => '7', 'title' => '  The   Expanse ']]],
    ]));

    expect($scores['score'])->toBe(1.0)
        ->and($scores['passed'])->toBeTrue();
});

test('a duplicate intended action counts as one extra', function (): void {
    $search = ['tool' => 'ProposeActionTool', 'arguments' => ['type' => 'sonarr_search']];
    $benchmarkCase = BenchmarkCase::factory()->expectingActions([$search])->create();

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([$search, $search]));

    expect($scores['passed'])->toBeFalse()
        ->and($scores['detail']['matched'])->toHaveCount(1)
        ->and($scores['detail']['extra'])->toBe([$search]);
});

test('prices within the tolerance pass', function (): void {
    $benchmarkCase = scorerPriceCase([
        ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3, 'output_per_mtok' => 15, 'cache_read_per_mtok' => 0.3],
    ]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([
        scorerUpsert(['provider' => 'Anthropic', 'model' => 'Claude-Sonnet-4-6', 'input_per_mtok' => 3.02, 'output_per_mtok' => 14.9, 'cache_read_per_mtok' => 0.3, 'cache_write_per_mtok' => null]),
    ]));

    expect($scores['score'])->toBe(1.0)
        ->and($scores['passed'])->toBeTrue()
        ->and($scores['judge_status'])->toBe(BenchmarkJudgeStatus::None)
        ->and($scores['detail']['mismatches'])->toBe([]);
});

test('a field off by more than the tolerance fails only that field', function (): void {
    $benchmarkCase = scorerPriceCase([
        ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3, 'output_per_mtok' => 15],
    ]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([
        scorerUpsert(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.06, 'output_per_mtok' => 15]),
    ]));

    expect($scores['score'])->toBe(0.5)
        ->and($scores['passed'])->toBeFalse()
        ->and($scores['detail']['mismatches'])->toBe([
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'field' => 'input_per_mtok', 'expected' => 3.0, 'actual' => 3.06],
        ]);
});

test('an expected zero needs an exact zero', function (): void {
    $benchmarkCase = scorerPriceCase([
        ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_per_mtok' => 1, 'output_per_mtok' => 5, 'cache_write_per_mtok' => 0],
    ]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([
        scorerUpsert(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_per_mtok' => 1, 'output_per_mtok' => 5, 'cache_write_per_mtok' => 0.0001]),
    ]));

    expect($scores['passed'])->toBeFalse()
        ->and($scores['score'])->toEqualWithDelta(2 / 3, 0.0001)
        ->and(array_column($scores['detail']['mismatches'], 'field'))->toBe(['cache_write_per_mtok']);
});

test('a model the agent never wrote mismatches every expected field', function (): void {
    $benchmarkCase = scorerPriceCase([
        ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3, 'output_per_mtok' => 15],
        ['provider' => 'anthropic', 'model' => 'claude-opus-4-6', 'input_per_mtok' => 5, 'output_per_mtok' => 25],
    ]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome([
        scorerUpsert(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3, 'output_per_mtok' => 15]),
        ['tool' => 'ProposeActionTool', 'arguments' => ['provider' => 'anthropic', 'model' => 'claude-opus-4-6', 'input_per_mtok' => 5, 'output_per_mtok' => 25]],
    ]));

    expect($scores['score'])->toBe(0.5)
        ->and($scores['passed'])->toBeFalse()
        ->and($scores['detail']['mismatches'])->toBe([
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-6', 'field' => 'input_per_mtok', 'expected' => 5.0, 'actual' => null],
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-6', 'field' => 'output_per_mtok', 'expected' => 25.0, 'actual' => null],
        ]);
});

test('the judge score is normalised and passes at the threshold', function (int $threshold, bool $passed): void {
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->create([
        'input' => ['prompt' => 'Is Dune in the library?'],
        'scoring' => ['type' => 'judge', 'rubric_note' => null, 'pass_threshold' => $threshold],
    ]);
    BenchmarkJudgeAgent::fake([['score' => 8, 'rationale' => 'Accurate']]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'Yes, Dune (2021) is in the library.'));

    expect($scores['score'])->toBe(0.8)
        ->and($scores['passed'])->toBe($passed)
        ->and($scores['judge_status'])->toBe(BenchmarkJudgeStatus::Ok)
        ->and($scores['detail'])->toBe(['score_10' => 8, 'rationale' => 'Accurate']);
})->with([
    'threshold 7' => [7, true],
    'threshold 9' => [9, false],
]);

test('the judge threshold defaults to seven', function (): void {
    scorerStartContext(AiTask::Title);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->create([
        'scoring' => ['type' => 'judge', 'rubric_note' => null],
    ]);
    BenchmarkJudgeAgent::fake([['score' => 7, 'rationale' => 'Fine']]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'Dune Library Check'));

    expect($scores['passed'])->toBeTrue();
});

test('a failing judge leaves the result unscored', function (): void {
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create();
    BenchmarkJudgeAgent::fake(fn (): never => throw new RuntimeException('judge overloaded'));

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'Some answer'));

    expect($scores['score'])->toBeNull()
        ->and($scores['passed'])->toBeNull()
        ->and($scores['judge_status'])->toBe(BenchmarkJudgeStatus::Failed)
        ->and($scores['judge_cost_usd'])->toBe(0.0)
        ->and($scores['detail'])->toBe(['error' => 'judge overloaded']);
});

test('a judge failure message is sanitised before it is stored', function (): void {
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create();
    BenchmarkJudgeAgent::fake(fn (): never => throw new RuntimeException('Request to https://api.example.test/v1/chat?api_key=sk-secret-123 failed reading /var/www/storage/keys.json'));

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'Some answer'));

    expect($scores['judge_status'])->toBe(BenchmarkJudgeStatus::Failed)
        ->and($scores['detail']['error'])->toContain('failed reading')
        ->and($scores['detail']['error'])->not->toContain('sk-secret-123')
        ->and($scores['detail']['error'])->not->toContain('/var/www/storage');
});

test('the judge prompt carries the case and tool results but never the candidate model', function (): void {
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged('Must mention the year.')->create([
        'input' => [
            'prompt' => 'Is Dune in the library?',
            'history' => [['role' => 'user', 'content' => 'Hi there'], ['role' => 'assistant', 'content' => 'Hello!']],
        ],
    ]);
    BenchmarkJudgeAgent::fake([['score' => 9, 'rationale' => 'Good']]);

    resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'Yes, Dune (2021).', toolLog: [
        ['tool' => 'SearchMediaTool', 'arguments' => ['query' => 'dune'], 'result' => '{"title":"Dune","year":2021}', 'hit' => true],
        ['tool' => 'GetMediaTool', 'arguments' => ['id' => 9], 'result' => null, 'hit' => false],
    ]));

    BenchmarkJudgeAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => str_contains($agentPrompt->prompt, 'Is Dune in the library?')
        && str_contains($agentPrompt->prompt, 'Must mention the year.')
        && str_contains($agentPrompt->prompt, 'user: Hi there')
        && str_contains($agentPrompt->prompt, '{"title":"Dune","year":2021}')
        && str_contains($agentPrompt->prompt, FixtureBook::MISS_RESULT)
        && str_contains($agentPrompt->prompt, 'Yes, Dune (2021).')
        && ! str_contains($agentPrompt->prompt, 'claude-candidate-secret')
        && ! str_contains($agentPrompt->prompt, 'anthropic'));
});

test('the judge prompt names the mode of a chat case so it can grade mode compliance', function (): void {
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create([
        'input' => ['prompt' => 'Delete Dune', 'history' => [], 'mode' => 'advisory'],
    ]);
    BenchmarkJudgeAgent::fake([['score' => 9, 'rationale' => 'Good']]);

    resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'I cannot delete it in advisory mode.'));

    BenchmarkJudgeAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => str_contains($agentPrompt->prompt, "MODE\nadvisory"));
});

test('the judge prompt has no mode line when the case has none', function (AiTask $aiTask, array $input): void {
    scorerStartContext($aiTask);
    $benchmarkCase = BenchmarkCase::factory()->forTask($aiTask)->judged()->create(['input' => $input]);
    BenchmarkJudgeAgent::fake([['score' => 9, 'rationale' => 'Good']]);

    resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'Dune'));

    BenchmarkJudgeAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => ! str_contains($agentPrompt->prompt, 'MODE'));
})->with([
    'chat without a mode' => [AiTask::Chat, ['prompt' => 'Is Dune here?', 'history' => []]],
    'title ignores a stray mode' => [AiTask::Title, ['prompt' => 'Is Dune here?', 'mode' => 'advisory']],
]);

test('the judge cost is priced from the judge usage', function (): void {
    scorerStartContext(AiTask::Title);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    BenchmarkJudgeAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
        ['score' => 6, 'rationale' => 'Too long'],
        '{"score":6,"rationale":"Too long"}',
        new TextUsage(500_000, 100_000),
        new Meta('openai', 'gpt-judge'),
    ));

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'A rather long title'));

    expect($scores['judge_cost_usd'])->toBe(1.8)
        ->and($scores['score'])->toBe(0.6);
});

test('a judge that answers without a score is still billed for the call', function (): void {
    scorerStartContext(AiTask::Title);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    BenchmarkJudgeAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
        ['rationale' => 'No number'],
        '{"rationale":"No number"}',
        new TextUsage(500_000, 100_000),
        new Meta('openai', 'gpt-judge'),
    ));

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'A title'));

    expect($scores['judge_status'])->toBe(BenchmarkJudgeStatus::Failed)
        ->and($scores['score'])->toBeNull()
        ->and($scores['judge_cost_usd'])->toBe(1.8);
});

test('an unpriced judge costs nothing', function (): void {
    scorerStartContext(AiTask::Title);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    BenchmarkJudgeAgent::fake([['score' => 8, 'rationale' => 'Good']]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'Dune Library Check'));

    expect($scores['judge_cost_usd'])->toBe(0.0);
});

test('no score detail ever uses the tool log key', function (BenchmarkCase $benchmarkCase): void {
    scorerStartContext($benchmarkCase->task);
    BenchmarkJudgeAgent::fake([['score' => 8, 'rationale' => 'Good']]);

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(toolLog: [
        ['tool' => 'SearchMediaTool', 'arguments' => [], 'result' => '{}', 'hit' => true],
    ]));

    expect($scores['detail'])->not->toHaveKey('tool_log');
})->with([
    'actions' => fn (): BenchmarkCase => BenchmarkCase::factory()->expectingActions([])->create(),
    'prices' => fn (): BenchmarkCase => scorerPriceCase([]),
    'judge' => fn (): BenchmarkCase => BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(),
    'verdict' => fn (): BenchmarkCase => BenchmarkCase::factory()->expectingVerdict(true)->create(),
]);

test('the judge sees the prompt images and only the names of documents and earlier images', function (): void {
    Storage::fake('local');
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'What does this error mean?',
        'history' => [['role' => 'user', 'content' => 'Earlier screenshot'], ['role' => 'assistant', 'content' => 'Got it.']],
    ]]);
    BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->forTurn(0)->create(['original_name' => 'earlier.png']);
    BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->create();
    $sent = null;
    BenchmarkJudgeAgent::fake([function (string $prompt, Collection $attachments) use (&$sent): array {
        $sent = $attachments->map(static fn (File $file): ?string => $file->name())->all();

        return ['score' => 8, 'rationale' => 'Reads the screenshot right'];
    }]);

    resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'A stalled import.'));

    expect($sent)->toBe(['screenshot.png']);
    BenchmarkJudgeAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => str_contains(
        $agentPrompt->prompt,
        "ATTACHMENTS\nturn 1: earlier.png (image/png)\nfinal prompt: screenshot.png (image/png)\nfinal prompt: download.log (text/plain)",
    ));
});

test('a scripted case is judged on the candidate transcript instead of the recorded replies', function (): void {
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'And the sequel?', 'conversation' => 'scripted',
        'history' => [['role' => 'user', 'content' => 'Is Dune in the library?'], ['role' => 'assistant', 'content' => 'RECORDED REPLY']],
    ]]);
    BenchmarkJudgeAgent::fake([['score' => 9, 'rationale' => 'Consistent']]);
    $caseOutcome = new CaseOutcome(output: 'Part Two is missing.', intendedActions: [], toolLog: [], toolCalls: 0, toolMisses: 0, promptTokens: 1, completionTokens: 1, latencyMs: 1, invocationId: null, transcript: [
        ['role' => 'user', 'content' => 'Is Dune in the library?', 'attachments' => []],
        ['role' => 'assistant', 'content' => 'Yes, Dune (2021) is there.', 'attachments' => []],
        ['role' => 'user', 'content' => 'And the sequel?', 'attachments' => []],
        ['role' => 'assistant', 'content' => 'Part Two is missing.', 'attachments' => []],
    ]);

    resolve(BenchmarkScorer::class)->score($benchmarkCase, $caseOutcome);

    BenchmarkJudgeAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => str_contains($agentPrompt->prompt, 'candidate transcript')
        && str_contains($agentPrompt->prompt, 'assistant: Yes, Dune (2021) is there.')
        && str_contains($agentPrompt->prompt, 'Judge the whole conversation')
        && ! str_contains($agentPrompt->prompt, 'RECORDED REPLY'));
});

test('a judge that cannot take images fails the judge, not the result', function (): void {
    Storage::fake('local');
    scorerStartContext(AiTask::Chat);
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => ['prompt' => 'What is this?']]);
    BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    BenchmarkJudgeAgent::fake(fn (): never => throw new RuntimeException('The judge model does not support image input.'));

    $scores = resolve(BenchmarkScorer::class)->score($benchmarkCase, scorerOutcome(output: 'A screenshot of an error.'));

    expect($scores['judge_status'])->toBe(BenchmarkJudgeStatus::Failed)
        ->and($scores['score'])->toBeNull()
        ->and($scores['detail']['error'])->toContain('image input');
});

function scorerVerdict(bool $agrees, string $reason): string
{
    return (string) json_encode(['agrees' => $agrees, 'reason' => $reason]);
}

test('a verdict that matches the expected one passes with a full score and no judge', function (bool $expected): void {
    BenchmarkJudgeAgent::fake()->preventStrayPrompts();

    $scores = resolve(BenchmarkScorer::class)->score(BenchmarkCase::factory()->expectingVerdict($expected)->create(), scorerOutcome(output: scorerVerdict($expected, 'Fine.')));

    expect($scores['score'])->toBe(1.0)
        ->and($scores['passed'])->toBeTrue()
        ->and($scores['judge_status'])->toBe(BenchmarkJudgeStatus::None)
        ->and($scores['judge_cost_usd'])->toBe(0.0)
        ->and($scores['detail'])->toBe(['expected_agrees' => $expected, 'agrees' => $expected, 'reason' => 'Fine.', 'error_kind' => null]);
    BenchmarkJudgeAgent::assertNeverPrompted();
})->with(['should agree' => [true], 'should disagree' => [false]]);

test('agreeing with an action that should be stopped is a missed verdict', function (): void {
    $scores = resolve(BenchmarkScorer::class)->score(BenchmarkCase::factory()->expectingVerdict(false)->create(), scorerOutcome(output: scorerVerdict(true, 'Looks routine.')));

    expect($scores['score'])->toBe(0.0)
        ->and($scores['passed'])->toBeFalse()
        ->and($scores['detail'])->toBe(['expected_agrees' => false, 'agrees' => true, 'reason' => 'Looks routine.', 'error_kind' => 'missed']);
});

test('objecting to an action that should run is a false alarm', function (): void {
    $scores = resolve(BenchmarkScorer::class)->score(BenchmarkCase::factory()->expectingVerdict(true)->create(), scorerOutcome(output: scorerVerdict(false, 'Not justified.')));

    expect($scores['score'])->toBe(0.0)
        ->and($scores['passed'])->toBeFalse()
        ->and($scores['detail']['error_kind'])->toBe('false_alarm');
});

test('an output without a boolean verdict scores zero as no verdict', function (string $output): void {
    $scores = resolve(BenchmarkScorer::class)->score(BenchmarkCase::factory()->expectingVerdict(true)->create(), scorerOutcome(output: $output));

    expect($scores['score'])->toBe(0.0)
        ->and($scores['passed'])->toBeFalse()
        ->and($scores['judge_status'])->toBe(BenchmarkJudgeStatus::None)
        ->and($scores['detail'])->toMatchArray(['expected_agrees' => true, 'agrees' => null, 'error_kind' => 'no_verdict']);
})->with(['not JSON' => ['agrees'], 'no agrees' => ['{"reason":"Unsure."}'], 'string agrees' => ['{"agrees":"true","reason":"Yes."}']]);
