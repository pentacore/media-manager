<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Decision\DecisionPrompt;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Enums\AiTask;
use App\Enums\AiUsageKind;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('an admin writes a title case with judge scoring and sees it in the list', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-benchmark-case-new]')
        ->assertPathIs(route('admin.benchmarks.cases.create', absolute: false))
        ->select('[data-benchmark-task]', 'title')
        ->fill('[data-benchmark-name]', 'Short Nolan chat')
        ->fill('[data-benchmark-tags]', 'smoke, titles')
        ->fill('[data-benchmark-prompt]', 'Which films did Nolan direct?')
        ->assertVisible('[data-benchmark-judge]')
        ->assertVisible('[data-benchmark-default-rubric]')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.')
        ->assertSeeIn('[data-benchmark-case-row]', 'Short Nolan chat')
        ->assertSeeIn('[data-benchmark-case-row]', 'smoke')
        ->assertSeeIn('[data-benchmark-case-row]', 'Judge')
        ->assertSeeIn('[data-benchmark-case-row]', 'hand-written');

    $benchmarkCase = BenchmarkCase::query()->sole();

    expect($benchmarkCase->task)->toBe(AiTask::Title)
        ->and($benchmarkCase->tags)->toBe(['smoke', 'titles'])
        ->and($benchmarkCase->input['prompt'])->toBe('Which films did Nolan direct?')
        ->and($benchmarkCase->scoring['type'])->toBe('judge');
});

test('the form opens pre-filled from a usage record and warns about truncated data', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $record = AiUsageRecord::factory()->create([
        'agent_class' => MediaAgent::class,
        'kind' => AiUsageKind::Text,
        'prompt_text' => 'Find Dune on my server',
    ]);
    AiToolInvocation::factory()->create([
        'invocation_id' => $record->invocation_id,
        'tool_class' => SearchMediaTool::class,
        'tool_name' => 'SearchMediaTool',
        'arguments' => ['query' => 'Dune'],
        'result' => '{"hits":1}',
        'io_truncated' => true,
    ]);

    visit(route('admin.benchmarks.cases.create', ['from_usage' => $record->id], absolute: false))
        ->assertNoSmoke()
        ->assertValue('[data-benchmark-task]', 'chat')
        ->assertValue('[data-benchmark-prompt]', 'Find Dune on my server')
        ->assertVisible('[data-fixture-row]')
        ->assertValue('[data-fixture-row] [data-fixture-tool]', 'SearchMediaTool')
        ->assertValue('[data-fixture-row] [data-fixture-result]', '{"hits":1}')
        ->assertSeeIn('[data-benchmark-draft-warning]', 'truncated');
});

test('invalid fixture JSON shows an inline error and blocks the save', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'title')
        ->fill('[data-benchmark-name]', 'Broken fixture')
        ->fill('[data-benchmark-prompt]', 'Prompt')
        ->click('[data-fixture-add]')
        ->fill('[data-fixture-tool]', 'SearchMediaTool')
        ->fill('[data-fixture-arguments]', '{"query": ')
        ->click('[data-benchmark-save]')
        ->assertSeeIn('[data-fixture-arguments-error]', 'not valid JSON')
        ->assertVisible('[data-benchmark-blocked]')
        ->assertPathIs(route('admin.benchmarks.cases.create', absolute: false));

    expect(BenchmarkCase::query()->count())->toBe(0);
});

test('a decision case takes expected write actions from the tool list', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision')
        ->click('[data-benchmark-decision-source="text"]')
        ->assertVisible('[data-benchmark-event-key]')
        ->assertVisible('[data-expected-empty]')
        ->click('[data-expected-add]')
        ->assertVisible('[data-expected-row]')
        ->fill('[data-expected-arguments]', '{"action": "retry"}')
        ->fill('[data-benchmark-name]', 'Stuck import')
        ->fill('[data-benchmark-prompt]', 'Import failed')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');

    $benchmarkCase = BenchmarkCase::query()->sole();

    expect($benchmarkCase->scoring['type'])->toBe('actions')
        ->and($benchmarkCase->scoring['expected'])->toHaveCount(1)
        ->and($benchmarkCase->scoring['expected'][0]['arguments'])->toBe(['action' => 'retry']);
});

test('an admin edits a case and deletes it after confirming', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Old name']);

    visit(route('admin.benchmarks.cases.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-case-row]', 'Old name')
        ->click('[data-benchmark-case-row] a')
        ->assertPathIs(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->assertAttribute('[data-benchmark-task]', 'disabled', '')
        ->assertValue('[data-benchmark-name]', 'Old name')
        ->fill('[data-benchmark-name]', 'New name')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case updated.')
        ->assertSeeIn('[data-benchmark-case-row]', 'New name')
        ->click('[data-benchmark-case-delete]')
        ->assertSee('Delete "New name"?')
        ->click('[data-confirm-accept]')
        ->assertSee('Benchmark case deleted.')
        ->assertMissing('[data-benchmark-case-row]');

    expect(BenchmarkCase::query()->count())->toBe(0);
});

test('a price updater case saves its providers and numeric expected prices', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'price_updater')
        ->fill('[data-benchmark-name]', 'Gemini prices')
        ->fill('[data-benchmark-prompt]', 'Refresh prices for Google models')
        ->check('[data-benchmark-provider="gemini"]')
        ->fill('[data-benchmark-tolerance]', '2')
        ->click('[data-benchmark-price-add]')
        ->fill('[data-benchmark-price-provider]', 'gemini')
        ->fill('[data-benchmark-price-model]', 'gemini-2.5-pro')
        ->fill('[data-benchmark-price-field="input_per_mtok"]', '1.25')
        ->fill('[data-benchmark-price-field="output_per_mtok"]', '10')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');

    $benchmarkCase = BenchmarkCase::query()->sole();

    expect($benchmarkCase->input['providers'])->toBe(['gemini'])
        ->and($benchmarkCase->scoring['type'])->toBe('prices')
        ->and($benchmarkCase->scoring['tolerance_percent'])->toEqual(2)
        ->and($benchmarkCase->scoring['expected'][0]['model'])->toBe('gemini-2.5-pro')
        ->and($benchmarkCase->scoring['expected'][0]['input_per_mtok'])->toEqual(1.25)
        ->and($benchmarkCase->scoring['expected'][0]['output_per_mtok'])->toEqual(10)
        ->and($benchmarkCase->scoring['expected'][0]['cache_read_per_mtok'])->toBeNull();
});

test('saving a draft keeps the usage record it came from', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $record = AiUsageRecord::factory()->create([
        'agent_class' => MediaAgent::class,
        'kind' => AiUsageKind::Text,
        'prompt_text' => 'Find Dune on my server',
        'prompt_tokens' => 321,
        'completion_tokens' => 45,
    ]);

    visit(route('admin.benchmarks.cases.create', ['from_usage' => $record->id], absolute: false))
        ->assertNoSmoke()
        ->fill('[data-benchmark-name]', 'From usage')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');

    $benchmarkCase = BenchmarkCase::query()->sole();

    expect($benchmarkCase->source_invocation_id)->toBe($record->invocation_id)
        ->and($benchmarkCase->source_prompt_tokens)->toBe(321)
        ->and($benchmarkCase->source_completion_tokens)->toBe(45);
});

test('a server validation error is shown next to the nested row it belongs to', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'price_updater')
        ->fill('[data-benchmark-name]', 'Incomplete price')
        ->fill('[data-benchmark-prompt]', 'Refresh prices')
        ->check('[data-benchmark-provider="openai"]')
        ->click('[data-benchmark-price-add]')
        ->click('[data-benchmark-save]')
        ->assertSeeIn('[data-benchmark-price-row]', 'The scoring.expected.0.model field is required')
        ->assertPathIs(route('admin.benchmarks.cases.create', absolute: false));

    expect(BenchmarkCase::query()->count())->toBe(0);
});

test('switching away from the actions scoring clears an invalid expected-action flag', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision')
        ->click('[data-expected-add]')
        ->fill('[data-expected-arguments]', '{bad')
        ->click('[data-benchmark-name]')
        ->assertSeeIn('[data-expected-arguments-error]', 'not valid JSON')
        ->select('[data-benchmark-task]', 'title')
        ->fill('[data-benchmark-name]', 'Now a title case')
        ->fill('[data-benchmark-prompt]', 'Prompt')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');
});

test('an admin attaches files to a chat case, removes one and saves it as scripted', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'chat')
        ->fill('[data-benchmark-name]', 'Stuck screenshot')
        ->fill('[data-benchmark-prompt]', 'What does this error mean?')
        ->click('[data-benchmark-history-add]')
        ->fill('[data-benchmark-history-content]', 'Here is my first screenshot.')
        ->attach('[data-benchmark-attachments="final"] [data-benchmark-attachment-input]', base_path('tests/Fixtures/stuck-log.txt'))
        ->attach('[data-benchmark-attachments="0"] [data-benchmark-attachment-input]', base_path('tests/Fixtures/stuck-log.txt'))
        ->assertSeeIn('[data-benchmark-attachments="final"] [data-benchmark-attachment]', 'stuck-log.txt')
        ->assertSeeIn('[data-benchmark-attachments="0"] [data-benchmark-attachment]', 'stuck-log.txt')
        ->click('[data-benchmark-attachments="0"] [data-benchmark-attachment-remove]')
        ->assertMissing('[data-benchmark-attachments="0"] [data-benchmark-attachment]')
        ->select('[data-benchmark-replay-mode]', 'scripted')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');

    $benchmarkCase = BenchmarkCase::query()->sole();
    $benchmarkCaseAttachment = BenchmarkCaseAttachment::query()->sole();
    expect($benchmarkCase->isScripted())->toBeTrue()
        ->and($benchmarkCase->input['history'])->toBe([['role' => 'user', 'content' => 'Here is my first screenshot.']])
        ->and($benchmarkCaseAttachment->turn)->toBeNull()
        ->and($benchmarkCaseAttachment->original_name)->toBe('stuck-log.txt');
    Storage::disk('local')->assertExists($benchmarkCaseAttachment->path);
});

test('scripted mode greys out the recorded replies and saving keeps the attachments', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['name' => 'Dune chat', 'input' => [
        'prompt' => 'And the sequel?', 'mode' => null, 'conversation' => 'context',
        'history' => [['role' => 'user', 'content' => 'Is Dune in the library?'], ['role' => 'assistant', 'content' => 'Yes.']],
    ]]);
    $attachment = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();

    visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-attachments="final"] [data-benchmark-attachment]', 'screenshot.png')
        ->assertMissing('[data-benchmark-history-ignored]')
        ->assertMissing('[data-benchmark-scripted-hint]')
        ->select('[data-benchmark-replay-mode]', 'scripted')
        ->assertSeeIn('[data-benchmark-scripted-hint]', 'long conversations may stop at the time budget')
        ->assertSeeIn('[data-benchmark-history-ignored]', 'ignored in this mode')
        ->fill('[data-benchmark-name]', 'Dune chat, scripted')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case updated.');

    expect($benchmarkCase->fresh()->isScripted())->toBeTrue()
        ->and($attachment->fresh()?->turn)->toBeNull();
    Storage::disk('local')->assertExists($attachment->path);
});

test('a captured chat turn shows its attachment as a pending copy and copies it on save', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $conversationId = (string) Str::uuid();
    $turnAt = now()->startOfSecond()->subMinute();
    $chatAttachment = ChatAttachment::factory()->create(['conversation_id' => $conversationId]);
    DB::table('agent_conversations')->insert(['id' => $conversationId, 'title' => 'T', 'created_at' => $turnAt, 'updated_at' => $turnAt]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
        'role' => 'user', 'content' => 'What does this error mean?', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]',
        'attachments' => json_encode([['type' => 'stored-image', 'name' => null, 'path' => $chatAttachment->path, 'disk' => 'local']]),
        'created_at' => $turnAt, 'updated_at' => $turnAt,
    ]);
    $record = AiUsageRecord::factory()->create([
        'agent_class' => MediaAgent::class, 'kind' => AiUsageKind::Text, 'conversation_id' => $conversationId, 'created_at' => $turnAt,
        'prompt_text' => "What does this error mean?\n\nAttachments: screenshot.png",
    ]);

    visit(route('admin.benchmarks.cases.create', ['from_usage' => $record->id], absolute: false))
        ->assertNoSmoke()
        ->assertValue('[data-benchmark-prompt]', 'What does this error mean?')
        ->assertSeeIn('[data-benchmark-attachments="final"] [data-benchmark-attachment-pending]', 'copied on save')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');

    expect(Storage::disk('local')->get(BenchmarkCaseAttachment::query()->sole()->path))->toBe('png-bytes');
});

test('the case list badges attachments and scripted cases', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $chat = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['name' => 'Scripted screenshot chat', 'input' => ['prompt' => 'p', 'conversation' => 'scripted']]);
    BenchmarkCaseAttachment::factory()->count(2)->for($chat)->image()->create();
    $title = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Plain title case']);

    visit(route('admin.benchmarks.cases.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-benchmark-case-row="%d"] [data-benchmark-case-attachments]', $chat->id), '2 attachments')
        ->assertSeeIn(sprintf('[data-benchmark-case-row="%d"] [data-benchmark-case-scripted]', $chat->id), 'scripted')
        ->assertMissing(sprintf('[data-benchmark-case-row="%d"] [data-benchmark-case-attachments]', $title->id))
        ->assertMissing(sprintf('[data-benchmark-case-row="%d"] [data-benchmark-case-scripted]', $title->id));
});

test('a decision case is built from a webhook event with a scope and a prompt preview', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $payloadJson = '{"series": {"id": 7, "title": "Dune"}, "eventType": "Grab"}';

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision')
        ->assertAttribute('[data-benchmark-decision-source="event"]', 'aria-pressed', 'true')
        ->assertMissing('[data-benchmark-prompt]')
        ->select('[data-benchmark-event-service]', 'radarr')
        ->fill('[data-benchmark-event-type]', 'Grab')
        ->fill('[data-benchmark-event-payload]', $payloadJson)
        ->select('[data-benchmark-action-kind]', 'library_change')
        ->assertSeeIn('[data-benchmark-prompt-preview]', 'Service: radarr')
        ->assertSeeIn('[data-benchmark-prompt-preview]', '"title": "Dune"')
        ->fill('[data-benchmark-name]', 'Radarr grab')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.')
        ->assertSeeIn('[data-benchmark-case-row] [data-benchmark-case-webhook-event]', 'webhook event');

    $input = BenchmarkCase::query()->sole()->input;

    expect($input['event'])->toEqual(['service' => 'radarr', 'event_type' => 'Grab', 'payload_json' => $payloadJson])
        ->and($input['action_kind'])->toBe('library_change')
        ->and($input['event_key'])->toBe('radarr:Grab')
        ->and($input['prompt'])->toBe(DecisionPrompt::build('radarr', 'Grab', ['series' => ['id' => 7, 'title' => 'Dune'], 'eventType' => 'Grab']));
});

test('an existing free-text decision case opens on the free-text source and saves unchanged', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->create(['name' => 'Old decision', 'input' => ['prompt' => 'Download stuck at 99% for two days', 'event_key' => 'sonarr:Grab']]);

    visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->assertNoSmoke()
        ->assertAttribute('[data-benchmark-decision-source="text"]', 'aria-pressed', 'true')
        ->assertValue('[data-benchmark-prompt]', 'Download stuck at 99% for two days')
        ->assertValue('[data-benchmark-event-key]', 'sonarr:Grab')
        ->assertMissing('[data-benchmark-event-payload]')
        ->fill('[data-benchmark-name]', 'Renamed decision')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case updated.')
        ->assertMissing('[data-benchmark-case-webhook-event]');

    expect($benchmarkCase->fresh()->input)->toEqual(['prompt' => 'Download stuck at 99% for two days', 'event_key' => 'sonarr:Grab']);
});

test('a payload that is not a JSON object shows an inline error and blocks the save', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision')
        ->fill('[data-benchmark-event-type]', 'Grab')
        ->fill('[data-benchmark-event-payload]', '[1, 2]')
        ->fill('[data-benchmark-name]', 'Bad payload')
        ->assertSeeIn('[data-benchmark-event-payload-error]', 'The payload must be a JSON object.')
        ->click('[data-benchmark-save]')
        ->assertVisible('[data-benchmark-blocked]');

    expect(BenchmarkCase::query()->count())->toBe(0);
});
