<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;

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
