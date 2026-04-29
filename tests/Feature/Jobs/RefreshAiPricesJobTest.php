<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Events\AiPriceRefreshStateChanged;
use App\Jobs\RefreshAiPricesJob;
use App\Models\AiModelPrice;
use App\Models\User;
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
