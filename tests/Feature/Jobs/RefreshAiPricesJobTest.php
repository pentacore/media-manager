<?php

declare(strict_types=1);

use App\Events\AiPriceRefreshStateChanged;
use App\Jobs\RefreshAiPricesJob;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Cache::forget(RefreshAiPricesJob::LOCK_KEY);
});

/**
 * Build a coordinator report stub for the job delegation tests.
 */
function refreshReport(
    string $finalResult,
    int $modelsCreated = 0,
    ?string $errorMessage = null,
    array $fallbackProviders = [],
): RefreshReport {
    $failed = $finalResult === RefreshReport::RESULT_FAILED;

    return new RefreshReport(
        runId: 7,
        finalResult: $finalResult,
        modelsDevStatus: 'ok',
        providersRequested: 6,
        providersSucceeded: $failed ? 0 : 6,
        providersFailed: $failed ? 6 : 0,
        modelsCreated: $modelsCreated,
        modelsUpdated: 0,
        modelsUnchanged: 0,
        modelsLocked: 0,
        modelsRejected: 0,
        modelsTiered: 0,
        fallbackProviders: $fallbackProviders,
        errorMessage: $errorMessage,
    );
}

/**
 * Bind a duck-typed coordinator double whose run() invokes $handler and returns
 * its result. The coordinator is final, so it cannot be mocked; the job only
 * resolves the class from the container and calls run(), so any object with a
 * matching run() signature stands in. The double records its call arguments.
 */
function bindCoordinator(Closure $handler): object
{
    $double = new class($handler)
    {
        /** @var array{0: string, 1: string, 2: RefreshScope, 3: ?User, 4: string}|array{} */
        public array $captured = [];

        public function __construct(private readonly Closure $handler) {}

        public function run(string $mode, string $source, RefreshScope $scope, ?User $triggeredBy, string $trigger): RefreshReport
        {
            $this->captured = [$mode, $source, $scope, $triggeredBy, $trigger];

            return ($this->handler)($mode, $source, $scope, $triggeredBy, $trigger);
        }
    };

    app()->instance(AiPriceRefreshCoordinator::class, $double);

    return $double;
}

test('handle delegates to the coordinator with the admin hybrid apply contract', function (): void {
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();
    RefreshAiPricesJob::tryLock($admin->id);

    $coordinator = bindCoordinator(fn (): RefreshReport => refreshReport(RefreshReport::RESULT_SUCCEEDED));

    new RefreshAiPricesJob($admin)->handle();

    $captured = $coordinator->captured;

    expect($captured[0])->toBe(AiPriceRefreshCoordinator::MODE_APPLY)
        ->and($captured[1])->toBe(AiPriceRefreshCoordinator::SOURCE_HYBRID)
        ->and($captured[2])->toBeInstanceOf(RefreshScope::class)
        ->and($captured[2]->isBounded())->toBeFalse()
        ->and($captured[3]?->is($admin))->toBeTrue()
        ->and($captured[4])->toBe('admin');

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_RUNNING
        && $event->triggeredBy?->is($admin));

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('handle emits a succeeded event enriched with the coordinator report', function (): void {
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();

    bindCoordinator(fn (): RefreshReport => refreshReport(RefreshReport::RESULT_SUCCEEDED, modelsCreated: 4));

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED
        && $event->triggeredBy?->is($admin)
        && $event->report instanceof RefreshReport
        && $event->report->finalResult === RefreshReport::RESULT_SUCCEEDED
        && $event->report->modelsCreated === 4);

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('handle reports added/total deltas against AiModelPrice rows', function (): void {
    AiModelPrice::factory()->count(3)->create();
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();

    // The real coordinator persists rows; simulate exactly one new row so the
    // job's row-count delta is exercised without a live feed.
    bindCoordinator(function (): RefreshReport {
        AiModelPrice::factory()->create();

        return refreshReport(RefreshReport::RESULT_SUCCEEDED, modelsCreated: 1);
    });

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED
        && $event->added === 1
        && $event->total === 4);
});

test('a partial report rides the succeeded event carrying final_result partial', function (): void {
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();

    bindCoordinator(fn (): RefreshReport => refreshReport(RefreshReport::RESULT_PARTIAL, fallbackProviders: ['anthropic']));

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED
        && $event->report?->finalResult === RefreshReport::RESULT_PARTIAL);
    Event::assertNotDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_FAILED);

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('a failed report emits the failed state with the report error and clears the lock', function (): void {
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();
    RefreshAiPricesJob::tryLock($admin->id);

    bindCoordinator(fn (): RefreshReport => refreshReport(RefreshReport::RESULT_FAILED, errorMessage: 'monthly hard cap reached'));

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_FAILED
        && $event->error === 'monthly hard cap reached'
        && $event->report?->finalResult === RefreshReport::RESULT_FAILED);
    Event::assertNotDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED);

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('a coordinator exception emits the failed state and clears the lock', function (): void {
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();
    RefreshAiPricesJob::tryLock($admin->id);

    bindCoordinator(fn (): RefreshReport => throw new RuntimeException('coordinator exploded'));

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_FAILED
        && $event->error === 'coordinator exploded');
    Event::assertNotDispatched(fn (AiPriceRefreshStateChanged $event): bool => $event->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED);

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('failed broadcasts the failed state and clears the lock', function (): void {
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();
    RefreshAiPricesJob::tryLock($admin->id);

    new RefreshAiPricesJob($admin)->failed(new RuntimeException('boom'));

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $aiPriceRefreshStateChanged): bool => $aiPriceRefreshStateChanged->state === AiPriceRefreshStateChanged::STATE_FAILED
        && $aiPriceRefreshStateChanged->error === 'boom');

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('an event built from a RefreshReport populates the broadcast payload while keeping existing keys', function (): void {
    $report = new RefreshReport(
        runId: 42,
        finalResult: RefreshReport::RESULT_PARTIAL,
        modelsDevStatus: 'ok',
        providersRequested: 3,
        providersSucceeded: 2,
        providersFailed: 1,
        modelsCreated: 5,
        modelsUpdated: 4,
        modelsUnchanged: 6,
        modelsLocked: 1,
        modelsRejected: 2,
        modelsTiered: 3,
        fallbackProviders: ['anthropic', 'openai'],
        errorMessage: 'partial failure',
    );

    $event = new AiPriceRefreshStateChanged(
        state: AiPriceRefreshStateChanged::STATE_SUCCEEDED,
        summary: 'Refreshed catalog. 5 added.',
        added: 5,
        total: 20,
        report: $report,
    );

    $payload = $event->broadcastWith();

    expect($payload)
        ->toHaveKeys(['state', 'triggered_by', 'summary', 'error', 'added', 'total', 'occurred_at'])
        ->and($payload['state'])->toBe(AiPriceRefreshStateChanged::STATE_SUCCEEDED)
        ->and($payload['summary'])->toBe('Refreshed catalog. 5 added.')
        ->and($payload['added'])->toBe(5)
        ->and($payload['total'])->toBe(20);

    expect($payload)
        ->toHaveKeys([
            'run_id', 'final_result', 'models_dev_status',
            'providers_succeeded', 'providers_failed',
            'models_created', 'models_updated', 'models_locked',
            'models_rejected', 'models_tiered', 'fallback_providers',
        ])
        ->and($payload['run_id'])->toBe(42)
        ->and($payload['final_result'])->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($payload['models_dev_status'])->toBe('ok')
        ->and($payload['providers_succeeded'])->toBe(2)
        ->and($payload['providers_failed'])->toBe(1)
        ->and($payload['models_created'])->toBe(5)
        ->and($payload['models_updated'])->toBe(4)
        ->and($payload['models_locked'])->toBe(1)
        ->and($payload['models_rejected'])->toBe(2)
        ->and($payload['models_tiered'])->toBe(3)
        ->and($payload['fallback_providers'])->toBe(['anthropic', 'openai']);
});

test('an event without a report exposes null report keys and keeps existing keys', function (): void {
    $event = new AiPriceRefreshStateChanged(
        state: AiPriceRefreshStateChanged::STATE_RUNNING,
    );

    $payload = $event->broadcastWith();

    expect($payload)
        ->toHaveKeys(['state', 'summary', 'added', 'total', 'run_id', 'final_result', 'fallback_providers'])
        ->and($payload['state'])->toBe(AiPriceRefreshStateChanged::STATE_RUNNING)
        ->and($payload['run_id'])->toBeNull()
        ->and($payload['final_result'])->toBeNull()
        ->and($payload['models_dev_status'])->toBeNull()
        ->and($payload['fallback_providers'])->toBeNull();
});

test('tryLock is atomic and rejects a second caller until released', function (): void {
    expect(RefreshAiPricesJob::tryLock(1))->toBeTrue();
    expect(RefreshAiPricesJob::tryLock(2))->toBeFalse();
    expect(RefreshAiPricesJob::isRunning())->toBeTrue();

    Cache::forget(RefreshAiPricesJob::LOCK_KEY);

    expect(RefreshAiPricesJob::tryLock(3))->toBeTrue();
});
