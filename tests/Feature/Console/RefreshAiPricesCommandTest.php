<?php

declare(strict_types=1);

use App\Jobs\RefreshAiPricesJob;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::forget(RefreshAiPricesJob::LOCK_KEY);
});

/**
 * Build a succeeded coordinator report stub for the command delegation tests.
 */
function commandReport(string $finalResult = RefreshReport::RESULT_SUCCEEDED): RefreshReport
{
    $failed = $finalResult === RefreshReport::RESULT_FAILED;

    return new RefreshReport(
        runId: 11,
        finalResult: $finalResult,
        modelsDevStatus: 'ok',
        providersRequested: 6,
        providersSucceeded: $failed ? 0 : 6,
        providersFailed: $failed ? 6 : 0,
        modelsCreated: 0,
        modelsUpdated: 0,
        modelsUnchanged: 0,
        modelsLocked: 0,
        modelsRejected: 0,
        modelsTiered: 0,
    );
}

/**
 * Bind a duck-typed coordinator double whose run() records its arguments and
 * returns $handler's result. The coordinator is final and cannot be mocked; the
 * command only resolves the class and calls run(), so any object with a
 * matching run() signature stands in.
 */
function bindCommandCoordinator(Closure $handler): object
{
    $double = new class($handler)
    {
        /** @var array{0: string, 1: string, 2: RefreshScope, 3: ?User, 4: string, 5: bool}|array{} */
        public array $captured = [];

        public bool $called = false;

        public function __construct(private readonly Closure $handler) {}

        public function run(string $mode, string $source, RefreshScope $scope, ?User $triggeredBy, string $trigger, bool $dryRun = false): RefreshReport
        {
            $this->called = true;
            $this->captured = [$mode, $source, $scope, $triggeredBy, $trigger, $dryRun];

            return ($this->handler)($mode, $source, $scope, $triggeredBy, $trigger, $dryRun);
        }
    };

    app()->instance(AiPriceRefreshCoordinator::class, $double);

    return $double;
}

test('an invalid provider fails before the lock is acquired', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--provider' => ['not-a-provider']])
        ->assertFailed();

    expect($coordinator->called)->toBeFalse()
        ->and(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('an invalid source fails before the lock is acquired', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--source' => 'nonsense'])
        ->assertFailed();

    expect($coordinator->called)->toBeFalse()
        ->and(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('lock contention exits non-zero without invoking the coordinator', function (): void {
    RefreshAiPricesJob::tryLock('someone-else');

    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices')->assertFailed();

    expect($coordinator->called)->toBeFalse()
        // The pre-existing lock is left intact for its owner.
        ->and(RefreshAiPricesJob::isRunning())->toBeTrue();
});

test('a dry run passes MODE_DRY_RUN and writes nothing', function (): void {
    AiModelPrice::factory()->count(2)->create();

    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--dry-run' => true])->assertSuccessful();

    expect($coordinator->captured[0])->toBe(AiPriceRefreshCoordinator::MODE_DRY_RUN)
        ->and(AiModelPrice::query()->count())->toBe(2)
        ->and(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('the three source modes map to the coordinator source', function (string $option, string $expected): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--source' => $option])->assertSuccessful();

    expect($coordinator->captured[1])->toBe($expected)
        ->and($coordinator->captured[0])->toBe(AiPriceRefreshCoordinator::MODE_APPLY);
})->with([
    'hybrid' => ['hybrid', AiPriceRefreshCoordinator::SOURCE_HYBRID],
    'models-dev' => ['models-dev', AiPriceRefreshCoordinator::SOURCE_MODELS_DEV],
    'agent' => ['agent', AiPriceRefreshCoordinator::SOURCE_AGENT],
]);

test('verify with no provider defaults to the hybrid source over the core six', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--verify' => true])->assertSuccessful();

    $source = $coordinator->captured[1];
    $scope = $coordinator->captured[2];

    // --verify no longer forces the agent source: the default hybrid does
    // feed-then-verify, still bounded to the core six by default.
    expect($source)->toBe(AiPriceRefreshCoordinator::SOURCE_HYBRID)
        ->and($scope)->toBeInstanceOf(RefreshScope::class)
        ->and($scope->isBounded())->toBeTrue()
        ->and($scope->allowsProvider('openai'))->toBeTrue()
        ->and($scope->allowsProvider('anthropic'))->toBeTrue()
        ->and($scope->allowsProvider('gemini'))->toBeTrue()
        ->and($scope->allowsProvider('xai'))->toBeTrue()
        ->and($scope->allowsProvider('deepseek'))->toBeTrue()
        ->and($scope->allowsProvider('mistral'))->toBeTrue()
        // Groq/Cohere/OpenRouter are never verified by default.
        ->and($scope->allowsProvider('groq'))->toBeFalse();
});

test('verify with an explicit provider scopes to just that provider', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--verify' => true, '--provider' => ['anthropic']])
        ->assertSuccessful();

    $scope = $coordinator->captured[2];

    expect($coordinator->captured[1])->toBe(AiPriceRefreshCoordinator::SOURCE_HYBRID)
        ->and($scope->allowsProvider('anthropic'))->toBeTrue()
        ->and($scope->allowsProvider('openai'))->toBeFalse();
});

test('an upstream provider spelling is accepted and canonicalized', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    // `google` is the upstream key; the coordinator scope canonicalizes it.
    $this->artisan('ai:refresh-prices', ['--provider' => ['google']])->assertSuccessful();

    $scope = $coordinator->captured[2];

    expect($scope->allowsProvider('gemini'))->toBeTrue()
        ->and($scope->isBounded())->toBeTrue();
});

test('the command runs with a null user and the command trigger', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices')->assertSuccessful();

    expect($coordinator->captured[3])->toBeNull()
        ->and($coordinator->captured[4])->toBe('command');
});

test('a scheduled invocation records the schedule trigger', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--scheduled' => true])->assertSuccessful();

    expect($coordinator->captured[3])->toBeNull()
        ->and($coordinator->captured[4])->toBe('schedule');
});

test('verify records the verify mode over the default hybrid source', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--verify' => true])->assertSuccessful();

    expect($coordinator->captured[0])->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($coordinator->captured[1])->toBe(AiPriceRefreshCoordinator::SOURCE_HYBRID)
        ->and($coordinator->captured[5])->toBeFalse();
});

test('verify combined with dry-run runs a dry verification', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    // `--verify --dry-run` is valid (spec §22): the feed dry-runs, verification
    // targets are recorded, and the agent is skipped. Mode stays verify and the
    // dry-run flag is passed through to the coordinator.
    $this->artisan('ai:refresh-prices', ['--verify' => true, '--dry-run' => true])
        ->assertSuccessful();

    expect($coordinator->called)->toBeTrue()
        ->and($coordinator->captured[0])->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($coordinator->captured[1])->toBe(AiPriceRefreshCoordinator::SOURCE_HYBRID)
        ->and($coordinator->captured[5])->toBeTrue()
        ->and(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('verify with the models-dev source is rejected before the lock', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    // models-dev never invokes the verifier, so pairing it with --verify is
    // contradictory and fails fast without touching the lock.
    $this->artisan('ai:refresh-prices', ['--verify' => true, '--source' => 'models-dev'])
        ->assertFailed();

    expect($coordinator->called)->toBeFalse()
        ->and(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('verify with the agent source keeps the agent source for a feed-less verification', function (): void {
    $coordinator = bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices', ['--verify' => true, '--source' => 'agent'])
        ->assertSuccessful();

    expect($coordinator->captured[0])->toBe(AiPriceRefreshCoordinator::MODE_VERIFY)
        ->and($coordinator->captured[1])->toBe(AiPriceRefreshCoordinator::SOURCE_AGENT);
});

test('a failed report exits non-zero', function (): void {
    bindCommandCoordinator(fn (): RefreshReport => commandReport(RefreshReport::RESULT_FAILED));

    $this->artisan('ai:refresh-prices')->assertFailed();

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('the lock is released after a run completes', function (): void {
    bindCommandCoordinator(fn (): RefreshReport => commandReport());

    $this->artisan('ai:refresh-prices')->assertSuccessful();

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('the weekly refresh and monthly verify schedules are both registered', function (): void {
    $schedule = resolve(Schedule::class);

    $events = collect($schedule->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'ai:refresh-prices'));

    $weekly = $events->first(fn ($event): bool => ! str_contains((string) $event->command, '--verify'));
    $verify = $events->first(fn ($event): bool => str_contains((string) $event->command, '--verify'));

    expect($events)->toHaveCount(2)
        ->and($weekly)->not->toBeNull()
        ->and($weekly->expression)->toBe('0 0 * * 0')
        ->and((string) $weekly->command)->toContain('--scheduled')
        ->and($verify)->not->toBeNull()
        ->and($verify->expression)->toBe('0 0 1 * *')
        ->and((string) $verify->command)->toContain('--scheduled');
});
