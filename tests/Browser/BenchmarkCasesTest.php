<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Enums\AiTask;
use App\Enums\AiUsageKind;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\User;

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

    $case = BenchmarkCase::query()->sole();

    expect($case->task)->toBe(AiTask::Title)
        ->and($case->tags)->toBe(['smoke', 'titles'])
        ->and($case->input['prompt'])->toBe('Which films did Nolan direct?')
        ->and($case->scoring['type'])->toBe('judge');
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
        ->assertVisible('[data-benchmark-event-key]')
        ->assertVisible('[data-expected-empty]')
        ->click('[data-expected-add]')
        ->assertVisible('[data-expected-row]')
        ->fill('[data-expected-arguments]', '{"action": "retry"}')
        ->fill('[data-benchmark-name]', 'Stuck import')
        ->fill('[data-benchmark-prompt]', 'Import failed')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');

    $case = BenchmarkCase::query()->sole();

    expect($case->scoring['type'])->toBe('actions')
        ->and($case->scoring['expected'])->toHaveCount(1)
        ->and($case->scoring['expected'][0]['arguments'])->toBe(['action' => 'retry']);
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
