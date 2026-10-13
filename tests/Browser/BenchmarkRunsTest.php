<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\Agents\TitleAgent;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');

    foreach (['gpt-5-nano', 'gpt-5-mini', 'gpt-judge'] as $model) {
        AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model, 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
    }

    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-5-nano')->create();
    AiTaskModel::factory()->task(AiTask::BenchmarkJudge)->selecting('openai', 'gpt-judge')->create();
});

test('an admin sets up a run, estimates it and starts it', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat', 'input' => ['prompt' => 'Which films did Nolan direct?']]);
    TitleAgent::fake(fn (): array => ['title' => 'Nolan Films']);
    BenchmarkJudgeAgent::fake(fn (): array => ['score' => 8, 'rationale' => 'Accurate']);

    visit(route('admin.benchmarks.runs.create', absolute: false))
        ->assertNoSmoke()
        ->assertAttribute('[data-benchmark-start]', 'disabled', '')
        ->assertAttribute('[data-benchmark-estimate-button]', 'disabled', '')
        ->fill('[data-benchmark-run-name]', 'Title bake-off')
        ->click(sprintf('[data-benchmark-case-option="%d"]', $benchmarkCase->id))
        ->click('[data-benchmark-add-tiers]')
        ->assertVisible('[data-benchmark-candidate="0"]')
        ->click('[data-benchmark-add-candidate]')
        ->click('[data-benchmark-candidate="1"] [data-benchmark-model-select] button')
        ->click('[role="option"][aria-label="gpt-5-mini"]')
        ->click('[data-benchmark-estimate-button]')
        ->assertVisible('[data-benchmark-estimate]')
        ->assertSeeIn('[data-benchmark-estimate-total]', '$')
        ->click('[data-benchmark-candidate="1"] [data-benchmark-remove-candidate]')
        ->assertMissing('[data-benchmark-estimate]')
        ->assertAttribute('[data-benchmark-start]', 'disabled', '')
        ->click('[data-benchmark-add-candidate]')
        ->click('[data-benchmark-candidate="1"] [data-benchmark-model-select] button')
        ->click('[role="option"][aria-label="gpt-5-mini"]')
        ->click('[data-benchmark-estimate-button]')
        ->assertVisible('[data-benchmark-estimate]')
        ->click('[data-benchmark-start]')
        ->assertSee('Benchmark run started.')
        ->assertVisible('[data-benchmark-matrix]')
        ->assertSeeIn('[data-benchmark-matrix]', 'gpt-5-nano')
        ->assertSeeIn('[data-benchmark-matrix]', 'gpt-5-mini')
        ->assertSeeIn('[data-benchmark-matrix-row="0"]', '8.0');

    $benchmarkRun = BenchmarkRun::query()->sole();

    expect($benchmarkRun->name)->toBe('Title bake-off')
        ->and($benchmarkRun->status)->toBe(BenchmarkRunStatus::Completed)
        ->and($benchmarkRun->candidates)->toHaveCount(2);
});

test('a finished run shows the matrix and the drawer compares both candidates', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat', 'input' => ['prompt' => 'Which films did Nolan direct?']]);
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => 'provider_default'],
        ['provider' => 'openai', 'model' => 'gpt-5-mini', 'reasoning' => 'high'],
    ])->create(['judge' => ['provider' => 'openai', 'model' => 'gpt-5-mini', 'reasoning' => null], 'case_ids' => [$benchmarkCase->id]]);
    BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->scored(0.9, true)->create([
        'output' => 'Nolan Films Nano',
        'latency_ms' => 800,
        'score_detail' => ['score_10' => 9, 'rationale' => 'Very accurate', 'tool_log' => []],
    ]);
    BenchmarkResult::factory()->forCase($run, $benchmarkCase, 1)->scored(0.4, false)->create([
        'output' => 'Nolan Films Mini',
        'latency_ms' => 1200,
        'score_detail' => ['score_10' => 4, 'rationale' => 'Missed Tenet', 'tool_log' => [
            ['tool' => 'SearchMediaTool', 'arguments' => ['query' => 'Nolan'], 'result' => null, 'hit' => false],
            ['tool' => 'DeleteMediaTool', 'arguments' => ['id' => 4], 'result' => '{"error":"advisory_mode_blocks_destructive"}', 'hit' => true, 'write' => true, 'refused' => true],
        ]],
    ]);
    $benchmarkResult = BenchmarkResult::query()->where('candidate_index', 0)->sole();

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-status]', 'Completed')
        ->assertSeeIn('[data-benchmark-progress]', '2 / 2')
        ->assertSeeIn('[data-benchmark-matrix-row="0"]', '9.0')
        ->assertSeeIn('[data-benchmark-matrix-row="0"]', 'openai · gpt-5-nano')
        ->assertSeeIn('[data-benchmark-matrix-row="1"]', 'openai · gpt-5-mini · High')
        ->assertSeeIn('[data-benchmark-matrix-row="1"]', 'self-judged')
        ->assertSeeIn('[data-benchmark-case-grid] thead', 'openai · gpt-5-mini · High')
        ->assertSeeIn('[data-benchmark-case-grid]', 'Nolan chat')
        ->assertMissing('[data-benchmark-rejudge]')
        ->click(sprintf('[data-benchmark-cell="%d"]', $benchmarkResult->id))
        ->assertVisible('[data-benchmark-drawer]')
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"]', 'Nolan Films Nano')
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"]', 'Very accurate')
        ->assertSeeIn('[data-benchmark-drawer-candidate="1"]', 'Nolan Films Mini')
        ->assertSeeIn('[data-benchmark-drawer-candidate="1"]', 'Missed Tenet')
        ->assertSeeIn('[data-benchmark-drawer-candidate="1"] [data-benchmark-drawer-tool]', 'miss')
        ->assertSeeIn('[data-benchmark-drawer-candidate="1"] [data-benchmark-drawer-tool]:nth-child(2)', 'refused')
        ->assertSeeIn('[data-benchmark-drawer-prompt]', 'Which films did Nolan direct?');
});

test('an admin cancels a running run after confirming', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $run = BenchmarkRun::factory()->running()->create(['name' => 'Slow run', 'case_ids' => [$benchmarkCase->id]]);
    BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->create();

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-status]', 'Running')
        ->click('[data-benchmark-cancel]')
        ->assertSee('Cancel "Slow run"?')
        ->click('[data-confirm-accept]')
        ->assertSee('Benchmark run cancelled.')
        ->assertSeeIn('[data-benchmark-status]', 'Cancelled')
        ->assertMissing('[data-benchmark-cancel]');

    expect($run->refresh()->status)->toBe(BenchmarkRunStatus::Cancelled);
});

test('the runs index links to a run and deletes a finished one', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $run = BenchmarkRun::factory()->completed()->create(['name' => 'Old run', 'case_ids' => [1, 2]]);

    visit(route('admin.benchmarks.runs.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-run-row]', 'Old run')
        ->assertSeeIn('[data-benchmark-run-row]', 'Completed')
        ->assertSeeIn('[data-benchmark-run-row]', '2 × 1')
        ->click('[data-benchmark-run-delete]')
        ->assertSee('Delete "Old run"?')
        ->click('[data-confirm-accept]')
        ->assertSee('Benchmark run deleted.')
        ->assertMissing('[data-benchmark-run-row]');

    expect(BenchmarkRun::query()->find($run->id))->toBeNull();
});

test('a result whose judge failed is flagged and offers a re-judge', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $run = BenchmarkRun::factory()->completed()->create(['case_ids' => [$benchmarkCase->id]]);
    $result = BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Nolan Films',
        'judge_status' => BenchmarkJudgeStatus::Failed,
        'score_detail' => ['error' => 'judge timed out', 'tool_log' => []],
    ]);

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-benchmark-rejudge]')
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $result->id), 'judge failed')
        ->click(sprintf('[data-benchmark-cell="%d"]', $result->id))
        ->assertSeeIn('[data-benchmark-drawer-judge-failed]', 'judge failed')
        ->assertSeeIn('[data-benchmark-drawer-judge-error]', 'judge timed out');
});

test('an admin re-judges an unscored result and the cell shows its new score', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $run = BenchmarkRun::factory()->completed()->create(['case_ids' => [$benchmarkCase->id]]);
    $result = BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Nolan Films',
        'judge_status' => BenchmarkJudgeStatus::Failed,
        'score_detail' => ['error' => 'judge timed out', 'tool_log' => []],
    ]);
    BenchmarkJudgeAgent::fake(fn (): array => ['score' => 8, 'rationale' => 'Accurate']);

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $result->id), 'judge failed')
        ->click('[data-benchmark-rejudge]')
        ->assertSee('Re-judging 1 result.')
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $result->id), '8.0')
        ->assertMissing('[data-benchmark-rejudge]');

    expect($result->fresh()->judge_status)->toBe(BenchmarkJudgeStatus::Ok);
});

test('queued re-judges and running cases show as in progress', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $run = BenchmarkRun::factory()->running()->withCandidates([
        ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => 'provider_default'],
        ['provider' => 'openai', 'model' => 'gpt-5-mini', 'reasoning' => 'provider_default'],
    ])->create(['case_ids' => [$benchmarkCase->id]]);
    $queued = BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Nolan Films',
        'judge_status' => BenchmarkJudgeStatus::Queued,
    ]);
    $running = BenchmarkResult::factory()->forCase($run, $benchmarkCase, 1)->running()->create();

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-running-count]', '1 running')
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $queued->id), 're-judging…')
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $running->id), 'running')
        ->click(sprintf('[data-benchmark-cell="%d"]', $queued->id))
        ->assertVisible('[data-benchmark-drawer-rejudging]');
});

test('a re-judge that stalled shows as a failed judge and offers a re-judge', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $run = BenchmarkRun::factory()->completed()->create(['case_ids' => [$benchmarkCase->id]]);
    $result = BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->create([
        'status' => BenchmarkResultStatus::Ok,
        'output' => 'Nolan Films',
        'judge_status' => BenchmarkJudgeStatus::Pending,
        'score_detail' => ['error' => 'judge timed out', 'tool_log' => []],
    ]);
    BenchmarkResult::query()->whereKey($result->id)->toBase()->update(['updated_at' => now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 1)]);
    BenchmarkJudgeAgent::fake(fn (): array => ['score' => 7, 'rationale' => 'Fine']);

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $result->id), 'judge failed — stalled')
        ->assertVisible('[data-benchmark-rejudge]')
        ->click(sprintf('[data-benchmark-cell="%d"]', $result->id))
        ->assertSeeIn('[data-benchmark-drawer-judge-failed]', 'judge failed — stalled')
        ->assertSeeIn('[data-benchmark-drawer-judge-error]', 'judge timed out')
        ->assertMissing('[data-benchmark-drawer-rejudging]');
});

test('a running result whose worker died shows as stalled and stops the polling count', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $run = BenchmarkRun::factory()->cancelled()->create(['case_ids' => [$benchmarkCase->id]]);
    $stalled = BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->running()->create();
    BenchmarkResult::query()->whereKey($stalled->id)->toBase()->update(['updated_at' => now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 1)]);

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-stalled-count]', '1 stalled')
        ->assertMissing('[data-benchmark-running-count]')
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $stalled->id), 'stalled')
        ->assertAttributeContains(sprintf('[data-benchmark-cell="%d"]', $stalled->id), 'class', 'text-destructive')
        ->click(sprintf('[data-benchmark-cell="%d"]', $stalled->id))
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"] [data-benchmark-drawer-status]', 'stalled');

    expect($stalled->fresh()->status)->toBe(BenchmarkResultStatus::Running);
});

test('a cancelled run keeps polling while a case still runs and shows its result', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $run = BenchmarkRun::factory()->cancelled()->create(['case_ids' => [$benchmarkCase->id]]);
    $running = BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->running()->create();

    $webpage = visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-status]', 'Cancelled')
        ->assertSeeIn('[data-benchmark-running-count]', '1 running')
        ->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $running->id), 'running');

    $running->update(['status' => BenchmarkResultStatus::Ok, 'score' => 0.9, 'passed' => true]);

    $webpage->assertSeeIn(sprintf('[data-benchmark-cell="%d"]', $running->id), '9.0')
        ->assertMissing('[data-benchmark-running-count]');
});

test('a candidate scored on fewer cases shows its coverage and is never highlighted as best', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $first = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Nolan chat']);
    $second = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['name' => 'Villeneuve chat']);
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => 'provider_default'],
        ['provider' => 'openai', 'model' => 'gpt-5-mini', 'reasoning' => 'provider_default'],
    ])->create(['case_ids' => [$first->id, $second->id]]);
    BenchmarkResult::factory()->forCase($run, $first, 0)->scored(0.6, false)->create();
    BenchmarkResult::factory()->forCase($run, $second, 0)->scored(0.6, false)->create();
    BenchmarkResult::factory()->forCase($run, $first, 1)->scored(0.9, true)->create();
    BenchmarkResult::factory()->forCase($run, $second, 1)->failed('Provider timeout')->create();

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-benchmark-matrix-row="0"] [data-benchmark-avg-score]', '6.0')
        ->assertDontSeeIn('[data-benchmark-matrix-row="0"] [data-benchmark-avg-score]', '/')
        ->assertSeeIn('[data-benchmark-matrix-row="1"] [data-benchmark-avg-score]', '9.0 (1/2)')
        ->assertSeeIn('[data-benchmark-matrix-row="1"] [data-benchmark-pass-rate]', '100% (1/2)')
        ->assertAttributeContains('[data-benchmark-matrix-row="0"] [data-benchmark-avg-score]', 'class', 'text-success')
        ->assertAttributeDoesntContain('[data-benchmark-matrix-row="1"] [data-benchmark-avg-score]', 'class', 'text-success')
        ->assertAttributeDoesntContain('[data-benchmark-matrix-row="1"] [data-benchmark-pass-rate]', 'class', 'text-success');
});

test('use this pre-fills tier 1 on AI Models and saves only after Save', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->create();
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'gpt-5-mini', 'reasoning' => null],
    ])->create(['name' => 'Title bake-off', 'case_ids' => [$benchmarkCase->id]]);
    BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->scored(0.9, true)->create();
    BenchmarkResult::factory()->forCase($run, $benchmarkCase, 1)->failed('boom')->create();
    $existing = AiTaskModel::query()->count();

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-benchmark-matrix-row="1"] [data-benchmark-use-candidate]')
        ->click('[data-benchmark-matrix-row="0"] [data-benchmark-use-candidate]')
        ->assertVisible('[data-ai-models-prefill]')
        ->assertSeeIn('[data-ai-models-prefill]', 'Tier 1 of Conversation titles pre-filled from benchmark run Title bake-off')
        ->assertSeeIn('[data-task="title"] [data-model-select]', 'gpt-5-nano');

    expect(AiTaskModel::query()->count())->toBe($existing);

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->click('[data-benchmark-matrix-row="0"] [data-benchmark-use-candidate]')
        ->click('Save models')
        ->assertSee('AI models updated.')
        ->assertMissing('[data-ai-models-prefill]');

    expect(AiTaskModel::query()->forTask(AiTask::Title)->first())
        ->provider->toBe('openai')
        ->model->toBe('gpt-5-nano');
});
