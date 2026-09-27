<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Models\AiPriceRefreshRun;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();

    foreach (['models_dev', 'litellm', 'openrouter', 'xai'] as $source) {
        config()->set(sprintf('mediamanager.ai.pricing.%s.enabled', $source), false);
        config()->set(sprintf('mediamanager.ai.pricing.%s.retries', $source), 0);
    }

    config()->set('ai.providers.xai.key', 'xai-test-key');
});

function sourcesRun(RefreshScope $refreshScope, bool $dryRun = false, string $source = AiPriceRefreshCoordinator::SOURCE_HYBRID): RefreshReport
{
    return resolve(AiPriceRefreshCoordinator::class)->run(
        mode: AiPriceRefreshCoordinator::MODE_APPLY,
        source: $source,
        scope: $refreshScope,
        triggeredBy: null,
        trigger: 'test',
        dryRun: $dryRun,
    );
}

/**
 * @return array<string, mixed>
 */
function sourcesFeedModel(float $input, float $output): array
{
    return ['cost' => ['input' => $input, 'output' => $output], 'modalities' => ['output' => ['text']]];
}

/**
 * @return array<string, mixed>
 */
function sourcesLiteLlmModel(string $provider, float $inputPerToken, float $outputPerToken): array
{
    return ['litellm_provider' => $provider, 'mode' => 'chat', 'input_cost_per_token' => $inputPerToken, 'output_cost_per_token' => $outputPerToken];
}

test('openrouter api updates existing openrouter rows and records source statuses', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.openrouter.enabled', true);

    AiModelPrice::factory()->create([
        'provider' => 'openrouter',
        'model' => 'anthropic/claude-opus-5.5',
        'input_per_mtok' => 3.0,
        'output_per_mtok' => 15.0,
    ]);

    Http::fake(['openrouter.ai/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json')))]);

    $refreshReport = sourcesRun(RefreshScope::forProviders(['openrouter']));

    $row = AiModelPrice::query()->where('provider', 'openrouter')->where('model', 'anthropic/claude-opus-5.5')->sole();
    $run = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);

    PriceFetcherAgent::assertNeverPrompted();

    expect($row->input_per_mtok)->toBe('4.0000')
        ->and($row->output_per_mtok)->toBe('20.0000')
        ->and($row->pricing_source)->toBe(PricingSource::OpenRouter)
        ->and(AiModelPrice::query()->where('provider', 'openrouter')->count())->toBe(1)
        ->and($refreshReport->modelsCreateDisabled)->toBeGreaterThan(0)
        ->and($refreshReport->sourceStatuses['openrouter'])->toBe('ok')
        ->and($run->source_statuses['openrouter'])->toBe('ok')
        ->and($run->models_dev_status)->toBe('disabled');
});

test('agreeing feeds write a consensus row and count it', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.litellm.enabled', true);

    Http::fake([
        'models.dev/*' => Http::response(json_encode(['openai' => ['models' => ['gpt-agree' => sourcesFeedModel(2.5, 10.0)]]], JSON_THROW_ON_ERROR)),
        'raw.githubusercontent.com/*' => Http::response(json_encode(['gpt-agree' => sourcesLiteLlmModel('openai', 2.5e-06, 1.0e-05)], JSON_THROW_ON_ERROR)),
    ]);

    $refreshReport = sourcesRun(RefreshScope::forProviders(['openai']));
    $run = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);

    expect(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-agree')->sole()->pricing_source)->toBe(PricingSource::FeedConsensus)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($run->provider_results['openai']['consensus'])->toBe(1);
});

test('a feed conflict is not written and wakes the verifier for that exact model', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.litellm.enabled', true);

    Http::fake([
        'models.dev/*' => Http::response(json_encode(['openai' => ['models' => [
            'gpt-agree' => sourcesFeedModel(1.0, 2.0),
            'gpt-conflict' => sourcesFeedModel(1.0, 2.0),
        ]]], JSON_THROW_ON_ERROR)),
        'raw.githubusercontent.com/*' => Http::response(json_encode([
            'gpt-agree' => sourcesLiteLlmModel('openai', 1.0e-06, 2.0e-06),
            'gpt-conflict' => sourcesLiteLlmModel('openai', 3.0e-06, 2.0e-06),
        ], JSON_THROW_ON_ERROR)),
    ]);

    $refreshReport = sourcesRun(RefreshScope::forProviders(['openai']));
    $run = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);

    PriceFetcherAgent::assertPromptedTimes(1);

    expect(AiModelPrice::query()->where('model', 'gpt-conflict')->exists())->toBeFalse()
        ->and($run->fallback_targets)->toContain('openai:gpt-conflict')
        ->and($run->unverified_targets)->toContain('openai:gpt-conflict')
        ->and($run->provider_results['openai']['conflicts'])->toBe(1)
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_PARTIAL);
});

test('a dry run records the conflict target without invoking the verifier', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.litellm.enabled', true);

    Http::fake([
        'models.dev/*' => Http::response(json_encode(['openai' => ['models' => [
            'gpt-agree' => sourcesFeedModel(1.0, 2.0),
            'gpt-conflict' => sourcesFeedModel(1.0, 2.0),
        ]]], JSON_THROW_ON_ERROR)),
        'raw.githubusercontent.com/*' => Http::response(json_encode([
            'gpt-agree' => sourcesLiteLlmModel('openai', 1.0e-06, 2.0e-06),
            'gpt-conflict' => sourcesLiteLlmModel('openai', 3.0e-06, 2.0e-06),
        ], JSON_THROW_ON_ERROR)),
    ]);

    $refreshReport = sourcesRun(RefreshScope::forProviders(['openai']), dryRun: true);

    PriceFetcherAgent::assertNeverPrompted();

    expect(AiPriceRefreshRun::query()->findOrFail($refreshReport->runId)->fallback_targets)->toContain('openai:gpt-conflict')
        ->and(AiModelPrice::query()->count())->toBe(0);
});

test('a provider whose only model conflicts falls back as a whole provider', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.litellm.enabled', true);

    Http::fake([
        'models.dev/*' => Http::response(json_encode(['openai' => ['models' => ['gpt-conflict' => sourcesFeedModel(1.0, 2.0)]]], JSON_THROW_ON_ERROR)),
        'raw.githubusercontent.com/*' => Http::response(json_encode(['gpt-conflict' => sourcesLiteLlmModel('openai', 3.0e-06, 2.0e-06)], JSON_THROW_ON_ERROR)),
    ]);

    $refreshReport = sourcesRun(RefreshScope::forProviders(['openai']), dryRun: true);
    $run = AiPriceRefreshRun::query()->findOrFail($refreshReport->runId);

    expect($run->fallback_targets)->toBe(['openai'])
        ->and($run->provider_results['openai']['conflicts'])->toBe(1);
});

test('xai api writes are first-party verified and bypass the anomaly guard', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.xai.enabled', true);

    AiModelPrice::factory()->create([
        'provider' => 'xai',
        'model' => 'grok-5',
        'input_per_mtok' => 0.3,
        'output_per_mtok' => 1.5,
    ]);

    Http::fake(['api.x.ai/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/Xai/language-models.json')))]);

    sourcesRun(RefreshScope::forProviders(['xai']));

    $row = AiModelPrice::query()->where('provider', 'xai')->where('model', 'grok-5')->sole();

    expect($row->input_per_mtok)->toBe('3.0000')
        ->and($row->output_per_mtok)->toBe('15.0000')
        ->and($row->pricing_source)->toBe(PricingSource::XaiApi)
        ->and($row->pricing_verified_at)->not->toBeNull();
});

test('a down models.dev feed is covered by litellm without waking the verifier', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.litellm.enabled', true);

    Http::fake([
        'models.dev/*' => Http::response('down', 503),
        'raw.githubusercontent.com/*' => Http::response(json_encode(['gpt-ll' => sourcesLiteLlmModel('openai', 1.0e-06, 2.0e-06)], JSON_THROW_ON_ERROR)),
    ]);

    $refreshReport = sourcesRun(RefreshScope::forProviders(['openai']));

    PriceFetcherAgent::assertNeverPrompted();

    expect(AiModelPrice::query()->where('model', 'gpt-ll')->sole()->pricing_source)->toBe(PricingSource::LiteLlm)
        ->and($refreshReport->modelsDevStatus)->toBe('server_error')
        ->and($refreshReport->finalResult)->toBe(RefreshReport::RESULT_SUCCEEDED)
        ->and($refreshReport->errorMessage)->toBeNull();
});

test('the report prints each pricing source status', function (): void {
    PriceFetcherAgent::fake(['ok']);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);

    Http::fake(['models.dev/*' => Http::response(json_encode(['openai' => ['models' => ['gpt-x' => sourcesFeedModel(1.0, 2.0)]]], JSON_THROW_ON_ERROR))]);

    $refreshReport = sourcesRun(RefreshScope::forProviders(['openai']));

    expect($refreshReport->toConsoleLines())->toContain('Pricing sources: openrouter disabled, xai disabled, models_dev ok, litellm disabled.')
        ->and($refreshReport->toBroadcastArray()['source_statuses'])->toBe($refreshReport->sourceStatuses);
});
