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
use App\Models\BenchmarkRun;
use App\Models\ChatAttachment;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            ->component('Admin/Benchmarks/Cases/Index')
            ->has('cases.data', 2)
            ->has('cases.meta')
            ->has('tasks', count(AiTask::cases()) - 3)
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
            ->component('Admin/Benchmarks/Cases/Form')
            ->where('case', null)
            ->where('draft', null)
            ->where('draftWarnings', [])
            ->where('eventKeys', DecisionAgentSettings::availableEventKeys())
            ->has('writeTools')
            ->where('writeTools', fn ($tools): bool => collect($tools)->contains(ToolNameResolver::resolve(resolve(ProposeActionTool::class)))
                && collect($tools)->contains(ToolNameResolver::resolve(resolve(UpsertModelPriceTool::class)))
                && collect($tools)->doesntContain(ToolNameResolver::resolve(resolve(SearchMediaTool::class))))
            ->has('defaultRubrics.chat')
            ->has('defaultRubrics.title')
            ->missing('defaultRubrics.decision'));
});

test('a truncated prompt keeps only the conversation turns before the record second', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $turnAt = now()->startOfSecond()->subMinute();
    DB::table('agent_conversations')->insert(['id' => $conversationId, 'title' => 'T', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    foreach ([
        ['user', 'Earlier question', $turnAt->subMinutes(5)],
        ['assistant', 'Earlier answer', $turnAt->subMinutes(4)],
        ['user', 'A very long captured prompt that was cut', $turnAt],
        ['assistant', 'The captured answer', $turnAt],
    ] as [$role, $content, $at]) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
            'role' => $role, 'content' => $content, 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]',
            'meta' => '[]', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'A very long captured prompt that was cut…', [
        'conversation_id' => $conversationId, 'created_at' => $turnAt,
    ]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.input.history', [
            ['role' => 'user', 'content' => 'Earlier question'],
            ['role' => 'assistant', 'content' => 'Earlier answer'],
        ]));
});

test('a truncated prompt keeps only the earlier turns in a non-UTC app timezone', function (): void {
    config()->set('app.timezone', 'Europe/Stockholm');
    date_default_timezone_set('Europe/Stockholm');
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $turnAt = now()->startOfSecond()->subMinute();
    DB::table('agent_conversations')->insert(['id' => $conversationId, 'title' => 'T', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    foreach ([
        ['user', 'Earlier question', $turnAt->subMinutes(5)],
        ['assistant', 'Earlier answer', $turnAt->subMinutes(4)],
        ['user', 'A very long captured prompt that was cut', $turnAt],
        ['assistant', 'The captured answer', $turnAt],
    ] as [$role, $content, $at]) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
            'role' => $role, 'content' => $content, 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]',
            'meta' => '[]', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'A very long captured prompt that was cut…', [
        'conversation_id' => $conversationId, 'created_at' => $turnAt,
    ]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.input.history', [
            ['role' => 'user', 'content' => 'Earlier question'],
            ['role' => 'assistant', 'content' => 'Earlier answer'],
        ]));
});

test('the create form pre-fills a chat draft with the earlier conversation turns only', function (int $usageDelaySeconds): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $turnAt = now()->startOfSecond()->subMinute();
    DB::table('agent_conversations')->insert(['id' => $conversationId, 'title' => 'T', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    // The SDK stores the captured turn's own messages before its usage row.
    $messages = [
        ['user', 'First question', $turnAt->subMinutes(30)],
        ['assistant', 'First answer', $turnAt->subMinutes(29)],
        ['user', 'Second question', $turnAt->subMinutes(5)],
        ['assistant', 'Second answer', $turnAt->subMinutes(4)],
        ['user', 'Third question', $turnAt],
        ['assistant', 'Third answer', $turnAt],
        ['user', 'Later question', $turnAt->addMinutes(5)],
    ];

    foreach ($messages as [$role, $content, $at]) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
            'role' => $role, 'content' => $content, 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]',
            'meta' => '[]', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Third question', [
        'conversation_id' => $conversationId, 'prompt_tokens' => 321, 'completion_tokens' => 45,
        'created_at' => $turnAt->addSeconds($usageDelaySeconds),
    ]);
    benchmarkToolCall($aiUsageRecord, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => ['query' => 'Dune'], 'result' => '[]']);
    benchmarkToolCall($aiUsageRecord, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => ['query' => 'Arrival'], 'result' => '[1]']);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Cases/Form')
            ->where('draft.task', 'chat')
            ->where('draft.input.prompt', 'Third question')
            ->where('draft.input.history', [
                ['role' => 'user', 'content' => 'First question'],
                ['role' => 'assistant', 'content' => 'First answer'],
                ['role' => 'user', 'content' => 'Second question'],
                ['role' => 'assistant', 'content' => 'Second answer'],
            ])
            ->where('draft.input.mode', resolve(AiSettings::class)->mode()->value)
            ->where('draft.fixtures.0.arguments.query', 'Dune')
            ->where('draft.fixtures.1.arguments.query', 'Arrival')
            ->where('draft.scoring.type', 'judge')
            ->where('draft.scoring.pass_threshold', 7)
            ->where('draft.source_invocation_id', $aiUsageRecord->invocation_id)
            ->where('draft.source_prompt_tokens', 321)
            ->where('draft.source_completion_tokens', 45)
            ->where('draft.source_truncated', false)
            ->where('draftWarnings', []));
})->with([
    'usage row in the same second' => [0],
    'usage row in the next second' => [1],
]);

test('a decision draft expects the write tool calls', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = benchmarkTextUsage(DecisionAgent::class, 'Webhook: import failed');
    benchmarkToolCall($aiUsageRecord, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => ['query' => 'Dune']]);
    benchmarkToolCall($aiUsageRecord, ProposeActionTool::class, 'ProposeActionTool', ['arguments' => ['action' => 'retry', 'id' => 4]]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
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
    $aiUsageRecord = benchmarkTextUsage(PriceFetcherAgent::class, 'Refresh prices for Google and OpenAI models');
    benchmarkToolCall($aiUsageRecord, UpsertModelPriceTool::class, 'UpsertModelPriceTool', ['arguments' => [
        'provider' => 'google', 'model' => 'gemini-2.5-pro', 'input_per_mtok' => 1.25, 'output_per_mtok' => 10,
        'cache_read_per_mtok' => null, 'cache_write_per_mtok' => null, 'source_url' => 'https://x',
    ]]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
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
    $aiUsageRecord = benchmarkTextUsage(PriceFetcherAgent::class, 'Refresh the catalogue');

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertInertia(fn ($page) => $page->where('draft.input.providers', [])->has('draftWarnings', 1));
});

test('truncated or legacy invocations and a cut prompt are warned about', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = benchmarkTextUsage(TitleAgent::class, 'A cut prompt…');
    benchmarkToolCall($aiUsageRecord, SearchMediaTool::class, 'SearchMediaTool', ['io_truncated' => true]);
    benchmarkToolCall($aiUsageRecord, SearchMediaTool::class, 'SearchMediaTool', ['arguments' => null, 'result' => null, 'tool_name' => null]);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
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
    'attachments on a non-chat agent' => [['agent_class' => TitleAgent::class, 'prompt_text' => "Look\n\nAttachments: poster.png"]],
    'no prompt text' => [['prompt_text' => null]],
    'not a text record' => [['kind' => AiUsageKind::Embeddings]],
    'unknown agent' => [['agent_class' => 'App\\Nope']],
    'not benchmarkable' => [['agent_class' => BenchmarkJudgeAgent::class]],
]);

test('a usage row of a benchmark run cannot become a case', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Hi', ['benchmark_run_id' => BenchmarkRun::factory()->create()->id, 'is_benchmark' => true]);

    $this->from(route('admin.ai-usage.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertRedirect(route('admin.ai-usage.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', "Benchmark runs can't be captured as cases.");
});

test('store persists a case with the source fields and audits it', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload([
        'fixtures' => [['tool' => 'SearchMediaTool', 'arguments' => ['query' => 'Dune'], 'result' => '[]']],
        'source_invocation_id' => 'inv-1', 'source_prompt_tokens' => 10, 'source_completion_tokens' => 5, 'source_truncated' => true,
    ]))->assertRedirect(route('admin.benchmarks.cases.index'));

    $benchmarkCase = BenchmarkCase::query()->firstOrFail();
    expect($benchmarkCase->task)->toBe(AiTask::Title)
        ->and($benchmarkCase->name)->toBe('Title for a short chat')
        ->and($benchmarkCase->tags)->toBe(['smoke'])
        ->and($benchmarkCase->input)->toEqual(['prompt' => 'Which films did Nolan direct?'])
        ->and($benchmarkCase->fixtures)->toEqual([['tool' => 'SearchMediaTool', 'arguments' => ['query' => 'Dune'], 'result' => '[]']])
        ->and($benchmarkCase->scoring)->toEqual(['type' => 'judge', 'rubric_note' => null, 'pass_threshold' => 7])
        ->and($benchmarkCase->source_invocation_id)->toBe('inv-1')
        ->and($benchmarkCase->source_prompt_tokens)->toBe(10)
        ->and($benchmarkCase->source_truncated)->toBeTrue();

    $activityLog = ActivityLog::query()->where('action', 'benchmark_case.created')->firstOrFail();
    expect($activityLog->subject_id)->toBe($benchmarkCase->id);
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
    $benchmarkCase = BenchmarkCase::query()->where('name', 'D')->firstOrFail();
    expect($benchmarkCase->input)->toEqual(['prompt' => 'Which films did Nolan direct?', 'event_key' => $eventKey]);
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
    'fixture arguments as a list' => [['fixtures' => [['tool' => 'x', 'arguments' => ['dune', 'arrival'], 'result' => '']]], 'fixtures.0.arguments'],
    'expected arguments as a list' => [['task' => 'decision', 'scoring' => ['type' => 'actions', 'expected' => [['tool' => 'ProposeActionTool', 'arguments' => ['retry']]]]], 'scoring.expected.0.arguments'],
]);

test('empty tool arguments are accepted as an empty object', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.benchmarks.cases.store'), benchmarkCasePayload([
        'task' => 'decision',
        'fixtures' => [['tool' => 'SearchMediaTool', 'arguments' => [], 'result' => '[]']],
        'scoring' => ['type' => 'actions', 'expected' => [['tool' => 'ProposeActionTool', 'arguments' => []]]],
    ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.benchmarks.cases.index'));

    expect(BenchmarkCase::query()->sole()->fixtures[0]['arguments'])->toBe([]);
});

test('update and destroy change the case and are audited', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $case = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Old']);

    $this->get(route('admin.benchmarks.cases.edit', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Cases/Form')
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
    $aiUsageRecord = benchmarkTextUsage(DecisionAgent::class, 'Webhook: import failed');
    benchmarkToolCall($aiUsageRecord, 'App\\Fake\\UnresolvableBenchmarkTool', 'Odd');

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertInertia(fn ($page) => $page->has('draft.fixtures', 1)->has('draft.scoring.expected', 0));
});

/**
 * @param  array<string, mixed>  $meta
 */
function benchmarkChatReply(string $conversationId, CarbonInterface $at, array $meta = []): string
{
    DB::table('agent_conversations')->insertOrIgnore(['id' => $conversationId, 'title' => 'T', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
    $id = (string) Str::uuid7();
    DB::table('agent_conversation_messages')->insert([
        'id' => $id, 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
        'role' => 'assistant', 'content' => 'A reply', 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]',
        'meta' => json_encode($meta), 'created_at' => $at, 'updated_at' => $at,
    ]);

    return $id;
}

test('the create form pre-fills a draft from an invocation id', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Which films did Nolan direct?');

    $this->get(route('admin.benchmarks.cases.create', ['from_invocation' => $aiUsageRecord->invocation_id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.input.prompt', 'Which films did Nolan direct?')
            ->where('draft.source_invocation_id', $aiUsageRecord->invocation_id));
});

test('an unknown invocation id redirects back with an error toast', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->from(route('admin.benchmarks.cases.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_invocation' => 'nope']))
        ->assertRedirect(route('admin.benchmarks.cases.index'))
        ->assertInertiaFlash('toast.message', 'This AI Usage record was not found.');
});

test('a benchmark usage row captured by invocation id hits the existing refusal', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Prompt', ['is_benchmark' => true]);

    $this->from(route('admin.benchmarks.cases.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_invocation' => $aiUsageRecord->invocation_id]))
        ->assertRedirect(route('admin.benchmarks.cases.index'))
        ->assertInertiaFlash('toast.type', 'error');
});

test('a chat reply with an invocation id in its meta pre-fills the draft', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Meta prompt');
    $messageId = benchmarkChatReply((string) Str::uuid(), now()->subDay(), ['invocation_id' => $aiUsageRecord->invocation_id]);

    $this->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.source_invocation_id', $aiUsageRecord->invocation_id));
});

test('a chat reply without meta falls back to the earliest chat usage row at or after it', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $replyAt = now()->startOfSecond()->subMinute();
    $messageId = benchmarkChatReply($conversationId, $replyAt);

    benchmarkTextUsage(MediaAgent::class, 'Too early', ['conversation_id' => $conversationId, 'created_at' => $replyAt->subSecond()]);
    benchmarkTextUsage(TitleAgent::class, 'Title decoy', ['conversation_id' => $conversationId, 'created_at' => $replyAt]);
    benchmarkTextUsage(MediaAgent::class, 'Benchmark decoy', ['conversation_id' => $conversationId, 'created_at' => $replyAt, 'is_benchmark' => true]);
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'The turn', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSecond()]);
    benchmarkTextUsage(MediaAgent::class, 'Next turn', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSeconds(10)]);

    $this->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.input.prompt', 'The turn')
            ->where('draft.source_invocation_id', $aiUsageRecord->invocation_id));
});

test('a chat reply that cannot be traced redirects back with an error toast', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $replyAt = now()->startOfSecond()->subMinute();
    $messageId = benchmarkChatReply($conversationId, $replyAt);
    benchmarkTextUsage(MediaAgent::class, 'Too late', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSeconds(10)]);

    $this->from(route('admin.benchmarks.cases.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertRedirect(route('admin.benchmarks.cases.index'))
        ->assertInertiaFlash('toast.message', "This reply can't be traced to its AI Usage record.");
});

test('the chat reply fallback does not draft from the next turn of the conversation', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $replyAt = now()->startOfSecond()->subMinute();
    $messageId = benchmarkChatReply($conversationId, $replyAt);
    benchmarkChatReply($conversationId, $replyAt->addSeconds(2));
    benchmarkTextUsage(MediaAgent::class, 'Next turn', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSeconds(2)]);

    $this->from(route('admin.benchmarks.cases.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertRedirect(route('admin.benchmarks.cases.index'))
        ->assertInertiaFlash('toast.message', "This reply can't be traced to its AI Usage record.");
});

test('a user message id cannot be traced to a usage row', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $replyAt = now()->startOfSecond()->subMinute();
    $messageId = benchmarkChatReply($conversationId, $replyAt);
    DB::table('agent_conversation_messages')->where('id', $messageId)->update(['role' => 'user']);
    benchmarkTextUsage(MediaAgent::class, 'Prompt', ['conversation_id' => $conversationId, 'created_at' => $replyAt]);

    $this->from(route('admin.benchmarks.cases.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertRedirect(route('admin.benchmarks.cases.index'))
        ->assertInertiaFlash('toast.message', "This reply can't be traced to its AI Usage record.");
});

test('a reply whose meta invocation has no usage row cannot be traced', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $messageId = benchmarkChatReply((string) Str::uuid(), now()->subMinute(), ['invocation_id' => 'gone-invocation']);

    $this->from(route('admin.benchmarks.cases.index'))
        ->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertRedirect(route('admin.benchmarks.cases.index'))
        ->assertInertiaFlash('toast.message', "This reply can't be traced to its AI Usage record.");
});

test('the chat reply fallback window includes a usage row exactly five seconds later', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $replyAt = now()->startOfSecond()->subMinute();
    $messageId = benchmarkChatReply($conversationId, $replyAt);
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Edge turn', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSeconds(5)]);

    $this->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.source_invocation_id', $aiUsageRecord->invocation_id));
});

test('the chat reply fallback picks the earliest of several rows in the window', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $replyAt = now()->startOfSecond()->subMinute();
    $messageId = benchmarkChatReply($conversationId, $replyAt);
    benchmarkTextUsage(MediaAgent::class, 'Later', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSeconds(3)]);
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Earlier', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSecond()]);

    $this->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.source_invocation_id', $aiUsageRecord->invocation_id));
});

test('the chat reply fallback ignores sub-agent rows with a parent invocation', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $replyAt = now()->startOfSecond()->subMinute();
    $messageId = benchmarkChatReply($conversationId, $replyAt);
    benchmarkTextUsage(MediaAgent::class, 'Child', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSecond(), 'parent_invocation_id' => (string) Str::uuid()]);
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'Parent', ['conversation_id' => $conversationId, 'created_at' => $replyAt->addSeconds(2)]);

    $this->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $messageId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.source_invocation_id', $aiUsageRecord->invocation_id));
});

test('from_usage takes priority over from_invocation', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, 'By usage');
    $byInvocation = benchmarkTextUsage(MediaAgent::class, 'By invocation');

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id, 'from_invocation' => $byInvocation->invocation_id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('draft.source_invocation_id', $aiUsageRecord->invocation_id));
});

/**
 * @param  list<array<string, mixed>>  $attachments  stored SDK attachment JSON
 * @param  array<string, mixed>  $meta
 */
function benchmarkConversationMessage(string $conversationId, string $role, string $content, CarbonInterface $at, array $attachments = [], array $meta = []): string
{
    DB::table('agent_conversations')->insertOrIgnore(['id' => $conversationId, 'title' => 'T', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
    $id = (string) Str::uuid7();
    DB::table('agent_conversation_messages')->insert([
        'id' => $id, 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
        'role' => $role, 'content' => $content, 'attachments' => json_encode($attachments), 'steps' => '[]', 'usage' => '[]',
        'meta' => json_encode($meta), 'created_at' => $at, 'updated_at' => $at,
    ]);

    return $id;
}

/**
 * @return array<string, mixed>
 */
function benchmarkStoredAttachment(ChatAttachment $chatAttachment): array
{
    return ['type' => $chatAttachment->isImage() ? 'stored-image' : 'stored-document', 'name' => null, 'path' => $chatAttachment->path, 'disk' => 'local'];
}

/**
 * A two-turn conversation whose second user turn (with a screenshot) is captured; the first carries a log.
 *
 * @return array{0: AiUsageRecord, 1: ChatAttachment, 2: ChatAttachment, 3: string}
 */
function benchmarkCapturedChatWithAttachments(): array
{
    $conversationId = (string) Str::uuid();
    $turnAt = now()->startOfSecond()->subMinute();
    $log = ChatAttachment::factory()->create(['conversation_id' => $conversationId, 'path' => 'chat-attachments/1/download.log', 'original_name' => 'download.log', 'mime_type' => 'text/plain', 'size' => 64]);
    $screenshot = ChatAttachment::factory()->create(['conversation_id' => $conversationId]);
    benchmarkConversationMessage($conversationId, 'user', 'My download is stuck.', $turnAt->subMinutes(5), [benchmarkStoredAttachment($log)]);
    benchmarkConversationMessage($conversationId, 'assistant', 'Send a screenshot.', $turnAt->subMinutes(4));
    benchmarkConversationMessage($conversationId, 'user', 'What does this error mean?', $turnAt, [benchmarkStoredAttachment($screenshot)]);
    $aiUsageRecord = benchmarkTextUsage(MediaAgent::class, "What does this error mean?\n\nAttachments: screenshot.png", ['conversation_id' => $conversationId, 'created_at' => $turnAt]);
    $replyId = benchmarkConversationMessage($conversationId, 'assistant', 'A stalled import.', $turnAt, meta: ['mode' => 'advisory', 'invocation_id' => $aiUsageRecord->invocation_id]);

    return [$aiUsageRecord, $log, $screenshot, $replyId];
}

test('a chat capture drafts the attachments of every kept user turn and strips the list from the prompt', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    [$aiUsageRecord, $log, $screenshot] = benchmarkCapturedChatWithAttachments();

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.input.prompt', 'What does this error mean?')
            ->where('draft.input.history', [
                ['role' => 'user', 'content' => 'My download is stuck.'],
                ['role' => 'assistant', 'content' => 'Send a screenshot.'],
            ])
            ->where('draft.input.mode', 'advisory')
            ->where('draft.input.conversation', 'context')
            ->where('draft.draft_attachments', [
                ['chat_attachment_id' => $log->id, 'turn' => 0, 'name' => 'download.log', 'mime_type' => 'text/plain', 'size' => 64],
                ['chat_attachment_id' => $screenshot->id, 'turn' => null, 'name' => 'screenshot.png', 'mime_type' => 'image/png', 'size' => 1024],
            ])
            ->where('draft.source_truncated', false)
            ->where('draftWarnings', []));
});

test('a captured attachment whose file is gone is left out with a warning', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    [$aiUsageRecord, $log, $screenshot] = benchmarkCapturedChatWithAttachments();
    Storage::disk('local')->delete($screenshot->path);

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.draft_attachments', [
                ['chat_attachment_id' => $log->id, 'turn' => 0, 'name' => 'download.log', 'mime_type' => 'text/plain', 'size' => 64],
            ])
            ->where('draft.source_truncated', true)
            ->where('draftWarnings', ['Some attachments of this conversation are no longer stored and were left out.']));
});

test('a chat reply with attachments is captured from the chat message too', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    [, , $screenshot, $replyId] = benchmarkCapturedChatWithAttachments();

    $this->get(route('admin.benchmarks.cases.create', ['from_chat_message' => $replyId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.input.prompt', 'What does this error mean?')
            ->where('draft.draft_attachments.1.chat_attachment_id', $screenshot->id));
});
