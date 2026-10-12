<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Enums\AiTask;
use App\Enums\AiUsageKind;
use App\Models\ActivityLog;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function benchmarkCasePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'task' => 'title',
        'name' => 'Title for a short chat',
        'tags' => ['smoke'],
        'notes' => null,
        'input' => ['prompt' => 'Which films did Nolan direct?'],
        'fixtures' => [],
        'scoring' => ['type' => 'judge', 'rubric_note' => null, 'pass_threshold' => 7],
    ], $overrides);
}

function benchmarkTextUsage(string $agent, ?string $prompt, array $attributes = []): AiUsageRecord
{
    return AiUsageRecord::factory()->create([
        'agent_class' => $agent,
        'kind' => AiUsageKind::Text,
        'prompt_text' => $prompt,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function benchmarkToolCall(AiUsageRecord $record, string $toolClass, string $name, array $attributes = []): AiToolInvocation
{
    return AiToolInvocation::factory()->create([
        'invocation_id' => $record->invocation_id,
        'tool_class' => $toolClass,
        'tool_name' => $name,
        'arguments' => ['q' => 'x'],
        'result' => '{"ok":true}',
        ...$attributes,
    ]);
}

test('non-admins cannot reach the case pages', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $this->get(route('admin.benchmarks.cases.index'))->assertForbidden();
    $this->get(route('admin.benchmarks.cases.create'))->assertForbidden();
    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload())->assertForbidden();
});

test('the index lists cases and filters by task and tag', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $decision = BenchmarkCase::factory()->create(['name' => 'Stuck import', 'tags' => ['import', 'smoke']]);
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'A title', 'tags' => ['smoke'], 'fixtures' => [['tool' => 'x', 'arguments' => [], 'result' => '']]]);

    $this->get(route('admin.benchmarks.cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Cases/Index', false)
            ->has('cases.data', 2)
            ->has('cases.meta')
            ->has('tasks', count(AiTask::cases()) - 2)
            ->where('filters.task', null));

    $this->get(route('admin.benchmarks.cases.index', ['task' => 'decision']))
        ->assertInertia(fn ($page) => $page
            ->has('cases.data', 1)
            ->where('cases.data.0.id', $decision->id)
            ->where('cases.data.0.task_label', 'Decision agent')
            ->where('cases.data.0.scoring_type', 'actions')
            ->where('cases.data.0.fixture_count', 0)
            ->where('filters.task', 'decision'));

    $this->get(route('admin.benchmarks.cases.index', ['tag' => 'import']))
        ->assertInertia(fn ($page) => $page->has('cases.data', 1)->where('cases.data.0.id', $decision->id));

    $this->get(route('admin.benchmarks.cases.index', ['tag' => 'smoke']))
        ->assertInertia(fn ($page) => $page->has('cases.data', 2));
});

test('the create form has the option props and default rubrics', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.benchmarks.cases.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Cases/Form', false)
            ->where('case', null)
            ->where('draft', null)
            ->where('draftWarnings', [])
            ->where('eventKeys', DecisionAgentSettings::availableEventKeys())
            ->has('writeTools')
            ->where('writeTools', fn ($tools): bool => collect($tools)->contains(ToolNameResolver::resolve(resolve(ProposeActionTool::class)))
                && collect($tools)->contains(ToolNameResolver::resolve(resolve(UpsertModelPriceTool::class)))
                && ! collect($tools)->contains(ToolNameResolver::resolve(resolve(SearchMediaTool::class))))
            ->has('defaultRubrics.chat')
            ->has('defaultRubrics.title')
            ->missing('defaultRubrics.decision'));
});

test('the create form pre-fills a chat draft with the earlier conversation turns only', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    DB::table('agent_conversations')->insert(['id' => $conversationId, 'title' => 'T', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    $messages = [
        ['user', 'First question', now()->subMinutes(30)],
        ['assistant', 'First answer', now()->subMinutes(29)],
        ['user', 'Second question', now()->subMinutes(5)],
        ['assistant', 'Later answer', now()->addMinutes(5)],
    ];

    foreach ($messages as [$role, $content, $at]) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
            'role' => $role, 'content' => $content, 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]',
            'meta' => '[]', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    $record = benchmarkTextUsage(MediaAgent::class, 'Third question', [
        'conversation_id' => $conversationId, 'prompt_tokens' => 321, 'completion_tokens' => 45,
    ]);
    benchmarkToolCall($record, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => ['query' => 'Dune'], 'result' => '[]']);
    benchmarkToolCall($record, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => ['query' => 'Arrival'], 'result' => '[1]']);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $record->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Cases/Form', false)
            ->where('draft.task', 'chat')
            ->where('draft.input.prompt', 'Third question')
            ->where('draft.input.history', [
                ['role' => 'user', 'content' => 'First question'],
                ['role' => 'assistant', 'content' => 'First answer'],
                ['role' => 'user', 'content' => 'Second question'],
            ])
            ->where('draft.input.mode', resolve(AiSettings::class)->mode()->value)
            ->where('draft.fixtures.0.arguments.query', 'Dune')
            ->where('draft.fixtures.1.arguments.query', 'Arrival')
            ->where('draft.scoring.type', 'judge')
            ->where('draft.scoring.pass_threshold', 7)
            ->where('draft.source_invocation_id', $record->invocation_id)
            ->where('draft.source_prompt_tokens', 321)
            ->where('draft.source_completion_tokens', 45)
            ->where('draft.source_truncated', false)
            ->where('draftWarnings', []));
});

test('a decision draft expects the write tool calls', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $record = benchmarkTextUsage(DecisionAgent::class, 'Webhook: import failed');
    benchmarkToolCall($record, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => ['query' => 'Dune']]);
    benchmarkToolCall($record, ProposeActionTool::class, 'ProposeActionTool', ['arguments' => ['action' => 'retry', 'id' => 4]]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $record->id]))
        ->assertInertia(fn ($page) => $page
            ->where('draft.task', 'decision')
            ->has('draft.fixtures', 2)
            ->where('draft.scoring.type', 'actions')
            ->has('draft.scoring.expected', 1)
            ->where('draft.scoring.expected.0.tool', 'ProposeActionTool')
            ->where('draft.scoring.expected.0.arguments.action', 'retry'));
});

test('a price updater draft takes providers from the prompt and prices from upsert calls', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $record = benchmarkTextUsage(PriceFetcherAgent::class, 'Refresh prices for Google and OpenAI models');
    benchmarkToolCall($record, UpsertModelPriceTool::class, 'UpsertModelPriceTool', ['arguments' => [
        'provider' => 'google', 'model' => 'gemini-2.5-pro', 'input_per_mtok' => 1.25, 'output_per_mtok' => 10,
        'cache_read_per_mtok' => null, 'cache_write_per_mtok' => null, 'source_url' => 'https://x',
    ]]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $record->id]))
        ->assertInertia(fn ($page) => $page
            ->where('draft.task', 'price_updater')
            ->where('draft.input.providers', ['gemini', 'openai'])
            ->where('draft.scoring.type', 'prices')
            ->where('draft.scoring.tolerance_percent', 1)
            ->where('draft.scoring.expected.0.provider', 'gemini')
            ->where('draft.scoring.expected.0.model', 'gemini-2.5-pro')
            ->where('draft.scoring.expected.0.input_per_mtok', 1.25)
            ->where('draft.scoring.expected.0.cache_read_per_mtok', null));
});

test('a price updater draft without a recognisable provider warns', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $record = benchmarkTextUsage(PriceFetcherAgent::class, 'Refresh the catalogue');

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $record->id]))
        ->assertInertia(fn ($page) => $page->where('draft.input.providers', [])->has('draftWarnings', 1));
});

test('truncated or legacy invocations and a cut prompt are warned about', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $record = benchmarkTextUsage(TitleAgent::class, 'A cut prompt…');
    benchmarkToolCall($record, SearchMediaTool::class, 'SearchMediaTool', ['io_truncated' => true]);
    benchmarkToolCall($record, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => null, 'result' => null, 'tool_name' => null]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $record->id]))
        ->assertInertia(fn ($page) => $page
            ->has('draftWarnings', 3)
            ->where('draft.source_truncated', true)
            ->where('draft.fixtures.1.tool', 'SearchMediaTool')
            ->where('draft.fixtures.1.arguments', [])
            ->where('draft.fixtures.1.result', ''));
});

test('records that cannot become a case redirect back with an error toast', function (array $attributes): void {
    $this->actingAs(User::factory()->admin()->create());
    $record = AiUsageRecord::factory()->create(['agent_class' => MediaAgent::class, 'kind' => AiUsageKind::Text, 'prompt_text' => 'Hi', ...$attributes]);

    $this->from(route('admin.ai-usage.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_usage' => $record->id]))
        ->assertRedirect(route('admin.ai-usage.index'))
        ->assertInertiaFlash('toast.type', 'error');
})->with([
    'attachments' => [['prompt_text' => "Look\n\nAttachments: poster.png"]],
    'no prompt text' => [['prompt_text' => null]],
    'not a text record' => [['kind' => AiUsageKind::Embeddings]],
    'unknown agent' => [['agent_class' => 'App\\Nope']],
    'not benchmarkable' => [['agent_class' => BenchmarkJudgeAgent::class]],
]);

test('store persists a case with the source fields and audits it', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload([
        'fixtures' => [['tool' => 'SearchMediaTool', 'arguments' => ['query' => 'Dune'], 'result' => '[]']],
        'source_invocation_id' => 'inv-1', 'source_prompt_tokens' => 10, 'source_completion_tokens' => 5, 'source_truncated' => true,
    ]))->assertRedirect(route('admin.benchmarks.cases.index'));

    $case = BenchmarkCase::query()->firstOrFail();
    expect($case->task)->toBe(AiTask::Title)
        ->and($case->name)->toBe('Title for a short chat')
        ->and($case->tags)->toBe(['smoke'])
        ->and($case->input)->toEqual(['prompt' => 'Which films did Nolan direct?'])
        ->and($case->fixtures)->toEqual([['tool' => 'SearchMediaTool', 'arguments' => ['query' => 'Dune'], 'result' => '[]']])
        ->and($case->scoring)->toEqual(['type' => 'judge', 'rubric_note' => null, 'pass_threshold' => 7])
        ->and($case->source_invocation_id)->toBe('inv-1')
        ->and($case->source_prompt_tokens)->toBe(10)
        ->and($case->source_truncated)->toBeTrue();

    $log = ActivityLog::query()->where('action', 'benchmark_case.created')->firstOrFail();
    expect($log->subject_id)->toBe($case->id);
});

test('store accepts decision, price updater and chat payload shapes', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $eventKey = DecisionAgentSettings::availableEventKeys()[0];

    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload([
        'task' => 'decision', 'name' => 'D',
        'input' => ['event_key' => $eventKey],
        'scoring' => ['type' => 'actions', 'expected' => [['tool' => 'ProposeActionTool', 'arguments' => ['a' => 1]]]],
    ]))->assertRedirect();

    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload([
        'task' => 'price_updater', 'name' => 'P',
        'input' => ['providers' => ['openai']],
        'scoring' => ['type' => 'prices', 'tolerance_percent' => 2, 'expected' => [[
            'provider' => 'openai', 'model' => 'gpt-5', 'input_per_mtok' => 1, 'output_per_mtok' => 8, 'cache_read_per_mtok' => null, 'cache_write_per_mtok' => 0,
        ]]],
    ]))->assertRedirect();

    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload([
        'task' => 'chat', 'name' => 'C',
        'input' => ['history' => [['role' => 'user', 'content' => 'hi']], 'mode' => 'advisory'],
        'scoring' => ['type' => 'judge', 'rubric_note' => 'be brief', 'pass_threshold' => 9],
    ]))->assertRedirect();

    expect(BenchmarkCase::query()->count())->toBe(3);
    $decision = BenchmarkCase::query()->where('name', 'D')->firstOrFail();
    expect($decision->input)->toEqual(['prompt' => 'Which films did Nolan direct?', 'event_key' => $eventKey]);
    $chat = BenchmarkCase::query()->where('name', 'C')->firstOrFail();
    expect($chat->input['mode'])->toBe('advisory')->and($chat->scoring['pass_threshold'])->toBe(9);
});

test('store validates every rule', function (array $overrides, string $errorKey): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload($overrides))
        ->assertSessionHasErrors($errorKey);

    expect(BenchmarkCase::query()->count())->toBe(0);
})->with([
    'unknown task' => [['task' => 'nope'], 'task'],
    'judge task' => [['task' => 'benchmark_judge'], 'task'],
    'failover task' => [['task' => 'failover'], 'task'],
    'name missing' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 161)], 'name'],
    'too many tags' => [['tags' => array_map(fn (int $i): string => 't'.$i, range(1, 11))], 'tags'],
    'tag too long' => [['tags' => [str_repeat('a', 41)]], 'tags.0'],
    'notes too long' => [['notes' => str_repeat('a', 2001)], 'notes'],
    'prompt missing' => [['input' => ['prompt' => '']], 'input.prompt'],
    'prompt too long' => [['input' => ['prompt' => str_repeat('a', 65_537)]], 'input.prompt'],
    'bad history role' => [['task' => 'chat', 'input' => ['history' => [['role' => 'system', 'content' => 'x']]]], 'input.history.0.role'],
    'history content too long' => [['task' => 'chat', 'input' => ['history' => [['role' => 'user', 'content' => str_repeat('a', 20_001)]]]], 'input.history.0.content'],
    'bad mode' => [['task' => 'chat', 'input' => ['mode' => 'wild']], 'input.mode'],
    'bad event key' => [['task' => 'decision', 'input' => ['event_key' => 'nope:Nope'], 'scoring' => ['type' => 'actions', 'expected' => []]], 'input.event_key'],
    'price updater without providers' => [['task' => 'price_updater', 'scoring' => ['type' => 'prices', 'expected' => []]], 'input.providers'],
    'non canonical provider' => [['task' => 'price_updater', 'input' => ['providers' => ['google']], 'scoring' => ['type' => 'prices', 'expected' => []]], 'input.providers.0'],
    'fixture tool missing' => [['fixtures' => [['tool' => '', 'arguments' => [], 'result' => '']]], 'fixtures.0.tool'],
    'fixture result too long' => [['fixtures' => [['tool' => 'x', 'arguments' => [], 'result' => str_repeat('a', 32_789)]]], 'fixtures.0.result'],
    'too many fixtures' => [['fixtures' => array_fill(0, 201, ['tool' => 'x', 'arguments' => [], 'result' => ''])], 'fixtures'],
    'scoring type mismatch for decision' => [['task' => 'decision', 'scoring' => ['type' => 'judge']], 'scoring.type'],
    'scoring type mismatch for judge task' => [['scoring' => ['type' => 'actions', 'expected' => []]], 'scoring.type'],
    'scoring type mismatch for prices' => [['task' => 'price_updater', 'input' => ['providers' => ['openai']], 'scoring' => ['type' => 'judge']], 'scoring.type'],
    'action without tool' => [['task' => 'decision', 'scoring' => ['type' => 'actions', 'expected' => [['arguments' => []]]]], 'scoring.expected.0.tool'],
    'negative price' => [['task' => 'price_updater', 'input' => ['providers' => ['openai']], 'scoring' => ['type' => 'prices', 'expected' => [['provider' => 'openai', 'model' => 'm', 'input_per_mtok' => -1, 'output_per_mtok' => 1]]]], 'scoring.expected.0.input_per_mtok'],
    'tolerance too high' => [['task' => 'price_updater', 'input' => ['providers' => ['openai']], 'scoring' => ['type' => 'prices', 'tolerance_percent' => 51, 'expected' => []]], 'scoring.tolerance_percent'],
    'threshold too high' => [['scoring' => ['pass_threshold' => 11]], 'scoring.pass_threshold'],
    'rubric note too long' => [['scoring' => ['rubric_note' => str_repeat('a', 2001)]], 'scoring.rubric_note'],
]);

test('update and destroy change the case and are audited', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $case = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Old']);

    $this->get(route('admin.benchmarks.cases.edit', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Cases/Form', false)
            ->where('case.id', $case->id)
            ->where('case.name', 'Old')
            ->has('case.input')
            ->has('case.fixtures')
            ->has('case.scoring')
            ->where('draft', null));

    $this->put(route('admin.benchmarks.cases.update', $case), benchmarkCasePayload(['name' => 'New']))
        ->assertRedirect(route('admin.benchmarks.cases.index'));

    expect($case->refresh()->name)->toBe('New')
        ->and(ActivityLog::query()->where('action', 'benchmark_case.updated')->where('subject_id', $case->id)->exists())->toBeTrue();

    $this->delete(route('admin.benchmarks.cases.destroy', $case))
        ->assertRedirect(route('admin.benchmarks.cases.index'));

    expect(BenchmarkCase::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'benchmark_case.deleted')->where('subject_id', $case->id)->exists())->toBeTrue();
});

test('a tool class the container cannot build does not break the draft', function (): void {
    $tool = new class(1) implements Tool
    {
        public function __construct(public int $required) {}

        public function description(): string
        {
            return 'x';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function handle(Request $request): string
        {
            return '';
        }
    };
    class_alias($tool::class, 'App\\Fake\\UnresolvableBenchmarkTool');
    $this->actingAs(User::factory()->admin()->create());
    $record = benchmarkTextUsage(DecisionAgent::class, 'Webhook: import failed');
    benchmarkToolCall($record, 'App\\Fake\\UnresolvableBenchmarkTool', 'Odd');

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $record->id]))
        ->assertInertia(fn ($page) => $page->has('draft.fixtures', 1)->has('draft.scoring.expected', 0));
});
