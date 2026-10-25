<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\ActionTypeConfig;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;
use App\Services\Benchmark\VerifierCaseInput;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * @return array<string, mixed>
 */
function verifierBrowserVerdict(bool $expected, bool $agrees, string $reason): array
{
    $errorKind = match (true) {
        $expected === $agrees => null,
        $agrees => 'missed',
        default => 'false_alarm',
    };

    return [
        'output' => (string) json_encode(['agrees' => $agrees, 'reason' => $reason]),
        'score_detail' => ['expected_agrees' => $expected, 'agrees' => $agrees, 'reason' => $reason, 'error_kind' => $errorKind, 'tool_log' => []],
    ];
}

test('a verifier run shows missed and false alarm counts and the drawer explains the verdict', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $stopIt = BenchmarkCase::factory()->expectingVerdict(false)->create(['name' => 'Wrong series delete']);
    $letItRun = BenchmarkCase::factory()->expectingVerdict(true)->create(['name' => 'Monitor new series']);
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => 'provider_default'],
        ['provider' => 'openai', 'model' => 'gpt-5-mini', 'reasoning' => 'provider_default'],
    ])->create(['case_ids' => [$stopIt->id, $letItRun->id]]);
    $missed = BenchmarkResult::factory()->forCase($run, $stopIt, 0)->scored(0.0, false)->create(verifierBrowserVerdict(false, true, 'Looks routine.'));
    BenchmarkResult::factory()->forCase($run, $letItRun, 0)->scored(0.0, false)->create(verifierBrowserVerdict(true, false, 'Not justified.'));
    BenchmarkResult::factory()->forCase($run, $stopIt, 1)->scored(1.0, true)->create(verifierBrowserVerdict(false, false, 'Targets another series.'));
    BenchmarkResult::factory()->forCase($run, $letItRun, 1)->scored(1.0, true)->create(verifierBrowserVerdict(true, true, 'Matches the event.'));

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-benchmark-missed-head]')
        ->assertSeeIn('[data-benchmark-matrix-row="0"] [data-benchmark-missed]', '1')
        ->assertAttributeContains('[data-benchmark-matrix-row="0"] [data-benchmark-missed]', 'class', 'text-destructive')
        ->assertSeeIn('[data-benchmark-matrix-row="0"] [data-benchmark-false-alarms]', '1')
        ->assertSeeIn('[data-benchmark-matrix-row="1"] [data-benchmark-missed]', '0')
        ->click(sprintf('[data-benchmark-cell="%d"]', $missed->id))
        ->assertVisible('[data-benchmark-drawer]')
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"] [data-benchmark-drawer-verdict]', 'Expected: Disagree')
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"] [data-benchmark-drawer-verdict]', 'Got: Agree')
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"] [data-benchmark-drawer-verdict-error]', 'Missed')
        ->assertSeeIn('[data-benchmark-drawer-candidate="0"] [data-benchmark-drawer-verdict-reason]', 'Looks routine.')
        ->assertSeeIn('[data-benchmark-drawer-candidate="1"] [data-benchmark-drawer-verdict]', 'Got: Disagree')
        ->assertMissing('[data-benchmark-drawer-candidate="1"] [data-benchmark-drawer-verdict-error]');
});

test('a judged run has no missed or false alarm columns', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $run = BenchmarkRun::factory()->completed()->create(['case_ids' => [$benchmarkCase->id]]);
    BenchmarkResult::factory()->forCase($run, $benchmarkCase, 0)->scored(0.8, true)->create(['output' => 'Nolan Films']);

    visit(route('admin.benchmarks.runs.show', $run, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-benchmark-matrix]')
        ->assertMissing('[data-benchmark-missed-head]')
        ->assertMissing('[data-benchmark-missed]');
});

/**
 * @return array<string, mixed>
 */
function verifierBrowserFields(): array
{
    return [
        'action_type' => 'monitor_series',
        'action_label' => 'Monitor series',
        'title' => 'Monitor Severance',
        'description' => 'Turns monitoring on for Severance.',
        'details' => [],
        'verified' => true,
        'payload_json' => '{"series_id": 7, "monitored": true}',
        'rationale' => 'The series was added unmonitored.',
        'source_service' => 'sonarr',
        'event_type' => 'SeriesAdd',
        'event_payload_json' => '{"series": {"id": 7}}',
    ];
}

test('an admin writes a structured verifier case with a live preview and an expected verdict', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'label' => 'Delete series']);

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision_verifier')
        ->assertAttribute('[data-benchmark-verifier-source="structured"]', 'aria-pressed', 'true')
        ->assertMissing('[data-benchmark-prompt]')
        ->select('[data-verifier-action-type]', 'delete_series')
        ->assertValue('[data-verifier-action-label]', 'Delete series')
        ->fill('[data-verifier-title]', 'Delete series "Severance (2022)"')
        ->fill('[data-verifier-description]', 'Sonarr will delete the series and its files.')
        ->click('[data-verifier-detail-add]')
        ->fill('[data-verifier-detail-label]', 'Delete files')
        ->fill('[data-verifier-detail-value]', 'Yes')
        ->fill('[data-verifier-payload]', '{"series_id": 7}')
        ->fill('[data-verifier-rationale]', 'Emby reported the series removed.')
        ->fill('[data-verifier-source-service]', 'emby')
        ->fill('[data-verifier-event-type]', 'library.deleted')
        ->fill('[data-verifier-event-payload]', '{"Item": {"Name": "Severance"}}')
        ->assertSeeIn('[data-verifier-prompt-preview]', 'Type: delete_series (Delete series)')
        ->assertSeeIn('[data-verifier-prompt-preview]', 'Delete files: Yes')
        ->click('[data-verdict-expected="disagree"]')
        ->fill('[data-benchmark-name]', 'Wrong series delete')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.')
        ->assertSeeIn('[data-benchmark-case-row] [data-benchmark-case-verdict]', 'should disagree');

    $benchmarkCase = BenchmarkCase::query()->sole();

    expect($benchmarkCase->task)->toBe(AiTask::DecisionVerifier)
        ->and($benchmarkCase->scoring)->toEqual(['type' => 'verdict', 'expected_agrees' => false])
        ->and($benchmarkCase->input['verifier']['verified'])->toBeTrue()
        ->and($benchmarkCase->input['verifier']['details'])->toEqual([['label' => 'Delete files', 'value' => 'Yes']])
        ->and($benchmarkCase->input['prompt'])->toBe(VerifierCaseInput::prompt($benchmarkCase->input['verifier']));
});

test('editing a structured verifier case reopens its fields and its verdict', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->expectingVerdict(true)->create([
        'name' => 'Monitor new series',
        'input' => ['prompt' => VerifierCaseInput::prompt(verifierBrowserFields()), 'verifier' => verifierBrowserFields()],
    ]);

    visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->assertNoSmoke()
        ->assertAttribute('[data-benchmark-verifier-source="structured"]', 'aria-pressed', 'true')
        ->assertValue('[data-verifier-title]', 'Monitor Severance')
        ->assertValue('[data-verifier-event-type]', 'SeriesAdd')
        ->assertChecked('[data-verdict-expected="agree"]')
        ->fill('[data-verifier-title]', 'Monitor Severance (2022)')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case updated.');

    expect($benchmarkCase->fresh()->input['prompt'])->toContain('Monitor Severance (2022)');
});

test('a raw-prompt verifier case saves the pasted case file', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $prompt = "PROPOSED ACTION\nType: delete_series (Delete series)";

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision_verifier')
        ->click('[data-benchmark-verifier-source="text"]')
        ->assertMissing('[data-verifier-title]')
        ->fill('[data-benchmark-prompt]', $prompt)
        ->click('[data-verdict-expected="agree"]')
        ->fill('[data-benchmark-name]', 'Pasted case file')
        ->click('[data-benchmark-save]')
        ->assertSee('Benchmark case created.');

    expect(BenchmarkCase::query()->sole()->input)->toEqual(['prompt' => $prompt]);
});

test('a verifier case needs an expected verdict before it saves', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision_verifier')
        ->click('[data-benchmark-verifier-source="text"]')
        ->fill('[data-benchmark-prompt]', 'PROPOSED ACTION')
        ->fill('[data-benchmark-name]', 'No verdict')
        ->click('[data-benchmark-save]')
        ->assertSeeIn('[data-verdict-scoring]', 'Choose the expected verdict.');

    expect(BenchmarkCase::query()->count())->toBe(0);
});

test('a verifier payload that is not a JSON object blocks the save', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.cases.create', absolute: false))
        ->assertNoSmoke()
        ->select('[data-benchmark-task]', 'decision_verifier')
        ->fill('[data-verifier-payload]', '[1]')
        ->assertSeeIn('[data-verifier-payload-error]', 'The payload must be a JSON object.')
        ->fill('[data-benchmark-name]', 'Bad payload')
        ->click('[data-benchmark-save]')
        ->assertVisible('[data-benchmark-blocked]');

    expect(BenchmarkCase::query()->count())->toBe(0);
});
