<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Events\AiPriceRefreshStateChanged;
use App\Jobs\RefreshAiPricesJob;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Cache::forget(RefreshAiPricesJob::LOCK_KEY);
});

test('handle broadcasts running then succeeded and clears the lock', function (): void {
    PriceFetcherAgent::fake(['Refreshed catalog. 0 added.']);
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();
    RefreshAiPricesJob::tryLock($admin->id);

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $aiPriceRefreshStateChanged): bool => $aiPriceRefreshStateChanged->state === AiPriceRefreshStateChanged::STATE_RUNNING
        && $aiPriceRefreshStateChanged->triggeredBy?->is($admin));
    Event::assertDispatched(fn (AiPriceRefreshStateChanged $aiPriceRefreshStateChanged): bool => $aiPriceRefreshStateChanged->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED
        && $aiPriceRefreshStateChanged->triggeredBy?->is($admin)
        && $aiPriceRefreshStateChanged->summary !== null
        && str_contains($aiPriceRefreshStateChanged->summary, 'Refreshed catalog'));

    expect(RefreshAiPricesJob::isRunning())->toBeFalse();
});

test('handle reports added/total deltas against AiModelPrice rows', function (): void {
    AiModelPrice::factory()->count(3)->create();
    PriceFetcherAgent::fake(['done']);
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $aiPriceRefreshStateChanged): bool => $aiPriceRefreshStateChanged->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED
        && $aiPriceRefreshStateChanged->added === 0
        && $aiPriceRefreshStateChanged->total === 3);
});

test('handle refuses to run the agent once the hard budget cap is hit', function (): void {
    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'test-model',
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 2.0,
    ]);
    AiUsageRecord::create([
        'invocation_id' => 'test-'.bin2hex(random_bytes(8)),
        'agent_class' => 'TestAgent',
        'provider' => 'openai',
        'model' => 'test-model',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 1_000_000,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'completed',
    ]);
    resolve(AiSettings::class)->setHardBudgetUsd(1.0);

    // No PriceFetcherAgent::fake(): if the budget guard doesn't short-circuit,
    // the agent runs unfaked against dummy keys and the failure message differs.
    Event::fake([AiPriceRefreshStateChanged::class]);

    $admin = User::factory()->admin()->create();
    RefreshAiPricesJob::tryLock($admin->id);

    new RefreshAiPricesJob($admin)->handle();

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $aiPriceRefreshStateChanged): bool => $aiPriceRefreshStateChanged->state === AiPriceRefreshStateChanged::STATE_FAILED
        && $aiPriceRefreshStateChanged->error !== null
        && str_contains($aiPriceRefreshStateChanged->error, 'hard cap'));
    Event::assertNotDispatched(fn (AiPriceRefreshStateChanged $aiPriceRefreshStateChanged): bool => $aiPriceRefreshStateChanged->state === AiPriceRefreshStateChanged::STATE_SUCCEEDED);

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

test('tryLock is atomic and rejects a second caller until released', function (): void {
    expect(RefreshAiPricesJob::tryLock(1))->toBeTrue();
    expect(RefreshAiPricesJob::tryLock(2))->toBeFalse();
    expect(RefreshAiPricesJob::isRunning())->toBeTrue();

    Cache::forget(RefreshAiPricesJob::LOCK_KEY);

    expect(RefreshAiPricesJob::tryLock(3))->toBeTrue();
});
