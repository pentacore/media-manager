<?php

declare(strict_types=1);

use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\FixtureBook;
use App\Ai\Benchmark\ReplayTool;
use App\Ai\Benchmark\WriteToolDetector;
use App\Ai\Middleware\ReplayBenchmarkTools;
use App\Ai\ResolvedSelection;
use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Ai\Tools\PriceFetcher\WebFetchTool;
use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\ActionRequest;
use App\Models\AiToolInvocation;
use App\Models\BenchmarkRun;
use App\Settings\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;

/**
 * A read tool whose real handler must never run inside a benchmark step.
 */
class ReplayThrowingReadTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Looks a title up.';
    }

    public function handle(Request $request): Stringable|string
    {
        throw new RuntimeException('The real read tool ran.');
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * A destructive app tool whose real body must never run inside a benchmark step.
 */
class ReplayThrowingDestructiveTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Deletes a title.';
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    protected function execute(Request $request): array
    {
        throw new RuntimeException('The real destructive tool ran.');
    }
}

/**
 * A safe-write app tool whose real body must never run inside a benchmark step.
 */
class ReplayThrowingSafeWriteTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Marks a title watched.';
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function risk(): Risk
    {
        return Risk::SafeWrite;
    }

    protected function execute(Request $request): array
    {
        throw new RuntimeException('The real safe-write tool ran.');
    }
}

/**
 * A tool-using agent that runs the replay middleware, for end-to-end checks.
 */
class ReplayProbeAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Probe.';
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ReplayBenchmarkTools];
    }

    /**
     * @return iterable<int, Agent|Tool>
     */
    public function tools(): iterable
    {
        return [new ReplayThrowingReadTool, new ReplayThrowingDestructiveTool, resolve(MediaFileInspectorAgent::class)];
    }
}

beforeEach(function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
});

function replayBook(): FixtureBook
{
    return new FixtureBook([
        ['tool' => 'ReplayThrowingReadTool', 'arguments' => ['query' => 'dune'], 'result' => '{"title":"Dune"}'],
        ['tool' => 'ReplayThrowingSafeWriteTool', 'arguments' => ['id' => 7], 'result' => '{"marked":true}'],
        ['tool' => 'InspectMediaFile', 'arguments' => ['task' => 'inspect dune'], 'result' => '{"ambiguous":false}'],
    ]);
}

function replayStartContext(FixtureBook $fixtureBook, ?AiMode $aiMode = null): BenchmarkRunContext
{
    $benchmarkRunContext = resolve(BenchmarkRunContext::class);
    $benchmarkRunContext->start(
        BenchmarkRun::factory()->create()->id,
        AiTask::Chat,
        new ResolvedSelection('openai', 'gpt-5-mini', AiReasoningLevel::ProviderDefault),
        null,
        $fixtureBook,
        [],
        $aiMode,
    );

    return $benchmarkRunContext;
}

/**
 * @param  array<int, mixed>  $tools
 */
function replayPendingStep(array $tools): PendingStep
{
    return new PendingStep(number: 0, isFinalStep: false, provider: 'openai', model: 'gpt-5-mini', instructions: null, messages: [], tools: $tools, schema: null, options: null);
}

/**
 * @return array<int, mixed>
 */
function replayToolsSeenBy(PendingStep $pendingStep): array
{
    $seen = null;
    (new ReplayBenchmarkTools)->handle($pendingStep, function (PendingStep $step) use (&$seen): string {
        $seen = $step;

        return 'ok';
    });

    return $seen->tools;
}

test('a read tool answers its recorded fixture without running', function (): void {
    $fixtureBook = replayBook();
    $replayTool = new ReplayTool(new ReplayThrowingReadTool, false, $fixtureBook, null);

    expect($replayTool->handle(new Request(['query' => ' Dune '])))->toBe('{"title":"Dune"}')
        ->and($replayTool->name())->toBe('ReplayThrowingReadTool')
        ->and($replayTool->description())->toBe('Looks a title up.')
        ->and($fixtureBook->calls())->toBe(1)
        ->and($fixtureBook->misses())->toBe(0);
});

test('an unrecorded read call returns the miss result and counts a miss', function (): void {
    $fixtureBook = replayBook();

    $result = new ReplayTool(new ReplayThrowingReadTool, false, $fixtureBook, null)->handle(new Request(['query' => 'arrival']));

    expect($result)->toBe(FixtureBook::MISS_RESULT)
        ->and($fixtureBook->misses())->toBe(1);
});

test('a write tool records every call as an intended action and returns the queued result', function (): void {
    $fixtureBook = replayBook();
    $replayTool = new ReplayTool(new ReplayThrowingDestructiveTool, true, $fixtureBook, AiMode::Executive);

    $first = $replayTool->handle(new Request(['id' => 3]));
    $second = $replayTool->handle(new Request(['id' => 3]));

    expect([$first, $second])->toBe([ReplayTool::QUEUED_RESULT, ReplayTool::QUEUED_RESULT])
        ->and($fixtureBook->intendedActions())->toBe([
            ['tool' => 'ReplayThrowingDestructiveTool', 'arguments' => ['id' => 3]],
            ['tool' => 'ReplayThrowingDestructiveTool', 'arguments' => ['id' => 3]],
        ])
        ->and($fixtureBook->log())->toBe([
            ['tool' => 'ReplayThrowingDestructiveTool', 'arguments' => ['id' => 3], 'result' => ReplayTool::QUEUED_RESULT, 'hit' => true, 'write' => true],
            ['tool' => 'ReplayThrowingDestructiveTool', 'arguments' => ['id' => 3], 'result' => ReplayTool::QUEUED_RESULT, 'hit' => true, 'write' => true],
        ])
        ->and($fixtureBook->calls())->toBe(2)
        ->and($fixtureBook->misses())->toBe(0)
        ->and(ActionRequest::count())->toBe(0);
});

test('a write tool replays its recorded result when one exists', function (): void {
    $fixtureBook = replayBook();

    $result = new ReplayTool(new ReplayThrowingSafeWriteTool, true, $fixtureBook, AiMode::Advisory)->handle(new Request(['id' => 7]));

    expect($result)->toBe('{"marked":true}')
        ->and($fixtureBook->intendedActions())->toHaveCount(1)
        ->and($fixtureBook->log())->toBe([['tool' => 'ReplayThrowingSafeWriteTool', 'arguments' => ['id' => 7], 'result' => '{"marked":true}', 'hit' => true, 'write' => true]])
        ->and($fixtureBook->misses())->toBe(0);
});

test('advisory mode refuses destructive tools without an intended action and logs the refusal', function (): void {
    $fixtureBook = replayBook();

    $result = new ReplayTool(new ReplayThrowingDestructiveTool, true, $fixtureBook, AiMode::Advisory)->handle(new Request(['id' => 3]));

    expect($result)->toBe(BaseTool::ADVISORY_REFUSAL)
        ->and($fixtureBook->intendedActions())->toBe([])
        ->and($fixtureBook->log())->toBe([[
            'tool' => 'ReplayThrowingDestructiveTool',
            'arguments' => ['id' => 3],
            'result' => BaseTool::ADVISORY_REFUSAL,
            'hit' => true,
            'write' => true,
            'refused' => true,
        ]])
        ->and($fixtureBook->calls())->toBe(1)
        ->and($fixtureBook->misses())->toBe(0);
});

test('the advisory refusal is the advisory_mode_blocks_destructive error BaseTool returns', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);
    $expected = [
        'error' => 'advisory_mode_blocks_destructive',
        'message' => 'The system is in Advisory mode. Tell the user to switch to Executive mode (Admin → AI Settings) if they want this action executed.',
    ];

    expect(json_decode(BaseTool::ADVISORY_REFUSAL, true))->toBe($expected)
        ->and(json_decode((new ReplayThrowingDestructiveTool)->handle(new Request(['id' => 3])), true))->toBe($expected);
});

test('read-only tools and sub-agents are not detected as writes', function (Tool $tool): void {
    expect(WriteToolDetector::writes($tool))->toBeFalse();
})->with([
    'read app tool' => fn (): Tool => resolve(WebFetchTool::class),
    'plain tool' => fn (): Tool => new ReplayThrowingReadTool,
    'sub-agent' => fn (): Tool => new AgentTool(resolve(MediaFileInspectorAgent::class)),
    'non-mutating decision tool' => fn (): Tool => resolve(InspectStuckImportTool::class),
]);

test('mutating tools are detected as writes', function (Tool $tool): void {
    expect(WriteToolDetector::writes($tool))->toBeTrue();
})->with([
    'destructive app tool' => fn (): Tool => new ReplayThrowingDestructiveTool,
    'safe-write app tool' => fn (): Tool => new ReplayThrowingSafeWriteTool,
    'price upsert' => fn (): Tool => resolve(UpsertModelPriceTool::class),
    'mutating decision tool' => fn (): Tool => resolve(ProposeActionTool::class),
]);

test('the middleware leaves tools untouched outside a run', function (): void {
    $pendingStep = replayPendingStep([new ReplayThrowingReadTool, new WebFetch]);
    $seen = null;

    (new ReplayBenchmarkTools)->handle($pendingStep, function (PendingStep $step) use (&$seen): string {
        $seen = $step;

        return 'ok';
    });

    expect($seen)->toBe($pendingStep);
});

test('the middleware drops provider tools, flattens tool search and wraps every tool for replay', function (): void {
    replayStartContext(replayBook());

    $tools = replayToolsSeenBy(replayPendingStep([
        new ReplayThrowingReadTool,
        new WebFetch,
        new CodeExecution,
        new ToolSearch([new ReplayThrowingDestructiveTool, new AgentTool(resolve(MediaFileInspectorAgent::class))]),
        resolve(MediaFileInspectorAgent::class),
    ]));

    expect($tools)->each->toBeInstanceOf(ReplayTool::class)
        ->and(array_map(static fn (ReplayTool $replayTool): string => $replayTool->name(), $tools))
        ->toBe(['ReplayThrowingReadTool', 'ReplayThrowingDestructiveTool', 'InspectMediaFile', 'InspectMediaFile']);
});

test('a benchmark run answers every tool call from fixtures and never runs a tool or sub-agent', function (): void {
    $fixtureBook = replayBook();
    replayStartContext($fixtureBook, AiMode::Executive);
    MediaFileInspectorAgent::fake(fn (): never => throw new RuntimeException('The sub-agent ran.'));
    ReplayProbeAgent::fake([
        new ToolCall(id: 'c1', name: 'ReplayThrowingReadTool', arguments: ['query' => 'dune']),
        new ToolCall(id: 'c2', name: 'InspectMediaFile', arguments: ['task' => 'inspect dune']),
        new ToolCall(id: 'c3', name: 'ReplayThrowingDestructiveTool', arguments: ['id' => 3]),
        'Done.',
    ]);

    $agentResponse = (new ReplayProbeAgent)->prompt('Delete Dune');

    expect($agentResponse->text)->toBe('Done.')
        ->and($fixtureBook->calls())->toBe(3)
        ->and(array_column($fixtureBook->log(), 'tool'))->toBe(['ReplayThrowingReadTool', 'InspectMediaFile', 'ReplayThrowingDestructiveTool'])
        ->and($fixtureBook->log()[2]['write'] ?? false)->toBeTrue()
        ->and($fixtureBook->misses())->toBe(0)
        ->and($fixtureBook->intendedActions())->toBe([['tool' => 'ReplayThrowingDestructiveTool', 'arguments' => ['id' => 3]]])
        ->and(ActionRequest::count())->toBe(0)
        ->and(AiToolInvocation::query()->orderBy('id')->pluck('tool_class')->all())
        ->toBe([ReplayThrowingReadTool::class, AgentTool::class, ReplayThrowingDestructiveTool::class]);
    MediaFileInspectorAgent::assertNeverPrompted();
});
