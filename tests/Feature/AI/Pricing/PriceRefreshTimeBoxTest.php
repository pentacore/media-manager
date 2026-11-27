<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiPriceRefreshRun;
use App\Models\AiTaskModel;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\PriceRefreshLedger;
use App\Services\AiUsage\Pricing\PriceRefreshTimeBox;
use App\Services\AiUsage\Pricing\PricingFeedFetcher;
use App\Services\AiUsage\Pricing\PricingTransportException;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;

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
            expect($pricingTransportException->category)->toBe(PricingTransportException::CATEGORY_SKIPPED_OUT_OF_TIME)
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
        ->and($refreshReport->errorMessage)->toBe('The price refresh ran out of time before its agent finished; providers it had not verified keep their stored prices.')
        ->and(AiPriceRefreshRun::query()->findOrFail($refreshReport->runId)->provider_results['anthropic']['status'])->toBe('fallback_failed')
        ->and(AiPriceRefreshRun::query()->findOrFail($refreshReport->runId)->status)->not->toBe('running');
});

test("the worker resetting scoped instances between jobs clears the previous job's deadline", function (): void {
    resolve(PriceRefreshTimeBox::class)->open(1);

    // Mirrors Worker::daemon resetting scoped() instances before every job
    // (F4): the next job must get a fresh, unopened box, never the previous
    // job's (possibly already-expired) deadline.
    app()->forgetScopedInstances();

    expect(resolve(PriceRefreshTimeBox::class)->hasRoomFor(86_400))->toBeTrue();
});

test('a provider failover restarting the verifier at step 0 still stops the run once the box no longer fits', function (): void {
    Sleep::fake();
    config()->set('ai.default', 'openai');
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();
    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS * 2);

    // The primary provider runs its step 0 with room to spare, burns the
    // box and then reports itself overloaded, so the SDK restarts the loop
    // on the failover provider at step 0 — which the middleware must still
    // refuse. Without that check the failover provider would be prompted.
    $providersTried = [];
    PriceFetcherAgent::fake(function (string $prompt, Collection $attachments, Provider $provider) use (&$providersTried): never {
        $providersTried[] = $provider->name();
        $this->travel(PriceRefreshTimeBox::AGENT_STEP_SECONDS + 10)->seconds();

        throw ProviderOverloadedException::forProvider($provider->name());
    });

    $refreshReport = resolve(AiPriceRefreshCoordinator::class)->run(
        mode: AiPriceRefreshCoordinator::MODE_APPLY,
        source: AiPriceRefreshCoordinator::SOURCE_AGENT,
        scope: RefreshScope::forProviders(['anthropic']),
        triggeredBy: null,
        trigger: 'test',
    );

    expect($providersTried)->toBe(['openai'])
        ->and($refreshReport->errorMessage)->toBe('The price refresh ran out of time before its agent finished; providers it had not verified keep their stored prices.')
        ->and(AiPriceRefreshRun::query()->findOrFail($refreshReport->runId)->status)->not->toBe('running');
});

test('a verifier that starts with the box already too tight is never prompted', function (): void {
    Sleep::fake();
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

    expect($refreshReport->errorMessage)->toBe('The price refresh ran out of time before its agent finished; providers it had not verified keep their stored prices.');
});

test('a mid-agent time-box stop resolves the provider the agent had already verified, instead of failing every provider', function (): void {
    Sleep::fake();

    // Two steps resolve anthropic for real (a WebFetchTool receipt, then a
    // receipt-backed UpsertModelPriceTool write); the next step's WebFetchTool
    // for gemini advances the clock as a side effect of its HTTP response, so
    // the box no longer fits ahead of gemini's own upsert — the agent is
    // stopped before gemini is ever verified.
    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS * 2);

    Http::fake([
        'claude.com/*' => Http::response('anthropic pricing page', 200),
        'ai.google.dev/*' => function () {
            $this->travel(PriceRefreshTimeBox::AGENT_STEP_SECONDS + 10)->seconds();

            return Http::response('gemini pricing page', 200);
        },
    ]);

    PriceFetcherAgent::fake([
        new ToolCall((string) Str::ulid(), 'WebFetchTool', ['url' => 'https://claude.com/pricing']),
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'anthropic',
            'model' => 'claude-verify',
            'input_per_mtok' => 1.0,
            'output_per_mtok' => 2.0,
            'cache_read_per_mtok' => null,
            'cache_write_per_mtok' => null,
            'reasoning_per_mtok' => null,
            'batch_input_per_mtok' => null,
            'batch_output_per_mtok' => null,
            'batch_cache_read_per_mtok' => null,
            'batch_cache_write_per_mtok' => null,
            'batch_reasoning_per_mtok' => null,
            'source_url' => 'https://claude.com/pricing',
            'source_updated_at' => null,
        ]),
        new ToolCall((string) Str::ulid(), 'WebFetchTool', ['url' => 'https://ai.google.dev/gemini-api/docs/pricing']),
        new ToolCall((string) Str::ulid(), 'UpsertModelPriceTool', [
            'provider' => 'gemini',
            'model' => 'gemini-verify',
            'input_per_mtok' => 1.0,
            'output_per_mtok' => 2.0,
            'cache_read_per_mtok' => null,
            'cache_write_per_mtok' => null,
            'reasoning_per_mtok' => null,
            'batch_input_per_mtok' => null,
            'batch_output_per_mtok' => null,
            'batch_cache_read_per_mtok' => null,
            'batch_cache_write_per_mtok' => null,
            'batch_reasoning_per_mtok' => null,
            'source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
            'source_updated_at' => null,
        ]),
        'ok',
    ]);

    $refreshReport = resolve(AiPriceRefreshCoordinator::class)->run(
        mode: AiPriceRefreshCoordinator::MODE_APPLY,
        source: AiPriceRefreshCoordinator::SOURCE_AGENT,
        scope: RefreshScope::forProviders(['anthropic', 'gemini']),
        triggeredBy: null,
        trigger: 'test',
    );

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);

    expect($aiPriceRefreshRun->provider_results['anthropic']['status'])->toBe(PriceRefreshLedger::PROVIDER_FALLBACK)
        ->and($aiPriceRefreshRun->provider_results['anthropic']['created'])->toBe(1)
        ->and($aiPriceRefreshRun->provider_results['gemini']['status'])->toBe(PriceRefreshLedger::PROVIDER_FALLBACK_FAILED)
        ->and($refreshReport->errorMessage)->toBe('The price refresh ran out of time before its agent finished; providers it had not verified keep their stored prices.')
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($aiPriceRefreshRun->status)->not->toBe('running')
        ->and(AiModelPrice::query()->where('provider', 'anthropic')->where('model', 'claude-verify')->exists())->toBeTrue()
        ->and(AiModelPrice::query()->where('provider', 'gemini')->where('model', 'gemini-verify')->exists())->toBeFalse();
});

test('a hybrid run skipped out of time mid-feed is recorded distinctly from a real upstream timeout, and still finalizes', function (): void {
    config()->set('mediamanager.ai.pricing.openrouter.enabled', true);
    config()->set('mediamanager.ai.pricing.openrouter.retries', 0);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.xai.enabled', false);
    config()->set('mediamanager.ai.pricing.litellm.enabled', false);

    // 250 s of room; the first source (openrouter) is answered only after
    // advancing the clock 200 s, leaving 50 s — short of the 93 s models.dev's
    // own worst case needs — so the second source is skipped without ever
    // being contacted.
    resolve(PriceRefreshTimeBox::class)->open(250);

    // openrouter has a row the feed answers for, so that provider resolves;
    // openai depends on the skipped models.dev feed and on a verifier that no
    // longer fits the remaining 50 s, so it does not: a partial run.
    PriceFetcherAgent::fake(['ok']);
    AiModelPrice::factory()->create([
        'provider' => 'openrouter',
        'model' => 'anthropic/claude-opus-5.5',
        'input_per_mtok' => 3.0,
        'output_per_mtok' => 15.0,
    ]);

    Http::fake([
        'openrouter.ai/*' => function () {
            $this->travel(200)->seconds();

            return Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json')));
        },
        'models.dev/*' => Http::response(['openai' => []]),
    ]);

    $refreshReport = resolve(AiPriceRefreshCoordinator::class)->run(
        mode: AiPriceRefreshCoordinator::MODE_APPLY,
        source: AiPriceRefreshCoordinator::SOURCE_HYBRID,
        scope: RefreshScope::forProviders(['openrouter', 'openai']),
        triggeredBy: null,
        trigger: 'test',
    );

    $aiPriceRefreshRun = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);

    PriceFetcherAgent::assertNeverPrompted();

    expect($aiPriceRefreshRun->source_statuses['models_dev'])->toBe(PricingTransportException::CATEGORY_SKIPPED_OUT_OF_TIME)
        ->and($aiPriceRefreshRun->source_statuses['models_dev'])->not->toBe(PricingTransportException::CATEGORY_TIMEOUT)
        ->and($aiPriceRefreshRun->source_statuses['openrouter'])->toBe('ok')
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL)
        ->and($aiPriceRefreshRun->status)->not->toBe('running');

    Http::assertSentCount(1);
});
