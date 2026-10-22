<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkNeutralRejudgeStatus;
use App\Jobs\RunNeutralJudgement;
use App\Models\ActivityLog;
use App\Models\AiModelPrice;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-neutral', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
});

/**
 * @return list<int>
 */
function endpointRuns(): array
{
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $runIds = [];

    foreach ([0, 1] as $position) {
        $benchmarkRun = BenchmarkRun::factory()->completed()->create(['candidates' => [['provider' => 'openai', 'model' => 'gpt-candidate', 'reasoning' => null]]]);
        BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->scored(0.8, true)->create(['completion_tokens' => 100]);
        $runIds[] = $benchmarkRun->id;
    }

    return $runIds;
}

/**
 * @param  list<int>  $runIds
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function endpointPayload(array $runIds, array $overrides = []): array
{
    return [
        'run_ids' => $runIds,
        'judge' => ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null],
        'cost_cap_usd' => 1,
        ...$overrides,
    ];
}

test('neutral re-judge endpoints are admin only', function (): void {
    $runIds = endpointRuns();
    $this->actingAs(User::factory()->member()->create());

    $this->postJson(route('admin.benchmarks.compare.estimate'), endpointPayload($runIds))->assertForbidden();
    $this->post(route('admin.benchmarks.compare.start'), endpointPayload($runIds))->assertForbidden();
});

test('the estimate prices the results still to judge', function (): void {
    $runIds = endpointRuns();
    $this->actingAs(User::factory()->admin()->create());

    $this->postJson(route('admin.benchmarks.compare.estimate'), endpointPayload($runIds))
        ->assertOk()
        ->assertJsonPath('results', 2)
        ->assertJsonPath('total_usd', 0.0058)
        ->assertJsonPath('unpriced', []);
});

test('an admin starts a neutral re-judge', function (): void {
    $runIds = endpointRuns();
    $this->actingAs(User::factory()->admin()->create());

    $this->from(route('admin.benchmarks.compare.show', ['runs' => implode(',', $runIds)]))
        ->post(route('admin.benchmarks.compare.start'), endpointPayload($runIds))
        ->assertRedirect(route('admin.benchmarks.compare.show', ['runs' => implode(',', $runIds)]))
        ->assertInertiaFlash('toast.message', 'Neutral re-judge started.');

    $benchmarkNeutralRejudge = BenchmarkNeutralRejudge::query()->sole();

    expect($benchmarkNeutralRejudge->status)->toBe(BenchmarkNeutralRejudgeStatus::Running)
        ->and((float) $benchmarkNeutralRejudge->estimate_usd)->toBe(0.0058)
        ->and((float) $benchmarkNeutralRejudge->cost_cap_usd)->toBe(1.0)
        ->and(ActivityLog::query()->where('action', 'benchmark.neutral_rejudge.started')->exists())->toBeTrue();
    Queue::assertPushed(RunNeutralJudgement::class, 2);
});

test('a start is refused', function (Closure $payload, string $field): void {
    $runIds = endpointRuns();
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.benchmarks.compare.start'), $payload($runIds))->assertSessionHasErrors($field);

    expect(BenchmarkNeutralRejudge::query()->count())->toBe(0);
})->with([
    'cap below the estimate' => [fn (array $runIds): array => endpointPayload($runIds, ['cost_cap_usd' => 0.001]), 'cost_cap_usd'],
    'no cap' => [fn (array $runIds): array => endpointPayload($runIds, ['cost_cap_usd' => null]), 'cost_cap_usd'],
    'an unpriced judge' => [fn (array $runIds): array => endpointPayload($runIds, ['judge' => ['provider' => 'openai', 'model' => 'gpt-unknown', 'reasoning' => null]]), 'judge.model'],
    'one run' => [fn (array $runIds): array => endpointPayload([$runIds[0]]), 'run_ids'],
    'a running run' => [fn (array $runIds): array => endpointPayload([$runIds[0], BenchmarkRun::factory()->running()->create()->id]), 'run_ids'],
]);

test('a start with nothing left to judge says so', function (): void {
    $runIds = endpointRuns();
    $this->actingAs(User::factory()->admin()->create());
    $this->post(route('admin.benchmarks.compare.start'), endpointPayload($runIds));

    $this->post(route('admin.benchmarks.compare.start'), endpointPayload($runIds))->assertSessionHasErrors(['judge' => 'Every result already has a score from this judge']);
});

test('a start while AI is off is not found', function (): void {
    $runIds = endpointRuns();
    config()->set('mediamanager.ai.enabled', false);
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.benchmarks.compare.start'), endpointPayload($runIds))
        ->assertNotFound();

    expect(BenchmarkNeutralRejudge::query()->count())->toBe(0);
});

test('an admin cancels a running re-judge once', function (): void {
    $rejudge = BenchmarkNeutralRejudge::factory()->running()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.benchmarks.compare.cancel', $rejudge))
        ->assertInertiaFlash('toast.message', 'Neutral re-judge cancelled.');
    $this->post(route('admin.benchmarks.compare.cancel', $rejudge))
        ->assertInertiaFlash('toast.message', 'This re-judge has already finished.');

    expect($rejudge->fresh()->status)->toBe(BenchmarkNeutralRejudgeStatus::Cancelled)
        ->and(ActivityLog::query()->where('action', 'benchmark.neutral_rejudge.cancelled')->count())->toBe(1);
});
