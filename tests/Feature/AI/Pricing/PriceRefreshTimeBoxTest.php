<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Models\AiPriceRefreshRun;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\PriceRefreshTimeBox;
use App\Services\AiUsage\Pricing\PricingFeedFetcher;
use App\Services\AiUsage\Pricing\PricingTransportException;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    config()->set('mediamanager.ai.pricing.models_dev.timeout', 30);
    config()->set('mediamanager.ai.pricing.models_dev.retries', 2);
});

test('a box that was never opened allows everything', function (): void {
    expect(resolve(PriceRefreshTimeBox::class)->hasRoomFor(86_400))->toBeTrue();
});

test('a pricing feed whose worst case no longer fits the box is skipped without a request', function (): void {
    Http::fake(['models.dev/*' => Http::response(['openai' => []])]);
    // 3 attempts x 30 s + 1 s + 2 s of back-off = 93 s.
    resolve(PriceRefreshTimeBox::class)->open(92);

    expect(fn (): mixed => resolve(PricingFeedFetcher::class)->fetch('models_dev', 'models.dev', 'TimeBoxTest'))
        ->toThrow(function (PricingTransportException $pricingTransportException): void {
            expect($pricingTransportException->category)->toBe(PricingTransportException::CATEGORY_TIMEOUT)
                ->and($pricingTransportException->getMessage())->toBe('Skipped the models.dev pricing API: the price refresh ran out of time.');
        });

    Http::assertNothingSent();
});

test('a pricing feed whose worst case still fits the box is fetched', function (): void {
    Http::fake(['models.dev/*' => Http::response(['openai' => []])]);
    resolve(PriceRefreshTimeBox::class)->open(93);

    expect(resolve(PricingFeedFetcher::class)->fetch('models_dev', 'models.dev', 'TimeBoxTest'))->toBe(['openai' => []]);
});

test('a verifier that no longer fits the box is never prompted and fails its providers with the time-limit message', function (): void {
    PriceFetcherAgent::fake(['ok']);
    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS - 1);

    $refreshReport = resolve(AiPriceRefreshCoordinator::class)->run(
        mode: AiPriceRefreshCoordinator::MODE_APPLY,
        source: AiPriceRefreshCoordinator::SOURCE_AGENT,
        scope: RefreshScope::forProviders(['anthropic']),
        triggeredBy: null,
        trigger: 'test',
    );

    PriceFetcherAgent::assertNeverPrompted();

    expect($refreshReport->finalResult)->toBe(RefreshReport::RESULT_FAILED)
        ->and($refreshReport->errorMessage)->toBe('The price refresh ran out of time before the verifier finished; the remaining providers keep their stored prices.')
        ->and(AiPriceRefreshRun::query()->findOrFail($refreshReport->runId)->provider_results['anthropic']['status'])->toBe('fallback_failed')
        ->and(AiPriceRefreshRun::query()->findOrFail($refreshReport->runId)->status)->not->toBe('running');
});
