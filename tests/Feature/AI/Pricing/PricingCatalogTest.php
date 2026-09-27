<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingCatalogResult;
use App\Services\AiUsage\Pricing\PricingCatalog;
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

/**
 * @param  array<string, string|int>  $responses  host pattern => fixture path or HTTP status
 */
function catalogFake(array $responses): void
{
    $fakes = [];

    foreach ($responses as $pattern => $response) {
        $fakes[$pattern] = is_int($response)
            ? Http::response('failure', $response)
            : Http::response((string) file_get_contents(base_path($response)));
    }

    Http::fake($fakes);
}

function catalogEnable(string ...$sources): void
{
    foreach ($sources as $source) {
        config()->set(sprintf('mediamanager.ai.pricing.%s.enabled', $source), true);
    }
}

/**
 * @return array<string, PricingSource> model => source
 */
function catalogSources(PricingCatalogResult $pricingCatalogResult, string $provider): array
{
    $sources = [];

    foreach ($pricingCatalogResult->providers[$provider]->candidates ?? [] as $candidate) {
        $sources[$candidate->model] = $candidate->source;
    }

    return $sources;
}

test('no enabled source means nothing is enabled', function (): void {
    expect(resolve(PricingCatalog::class)->anySourceEnabled())->toBeFalse();

    catalogEnable('litellm');

    expect(resolve(PricingCatalog::class)->anySourceEnabled())->toBeTrue();
});

test('openrouter api prices openrouter rows ahead of the models.dev slice', function (): void {
    catalogEnable('models_dev', 'openrouter');
    catalogFake([
        'models.dev/*' => 'tests/Fixtures/ModelsDev/api.json',
        'openrouter.ai/*' => 'tests/Fixtures/OpenRouter/models.json',
    ]);

    $result = resolve(PricingCatalog::class)->fetch(RefreshScope::all());

    expect(catalogSources($result, 'openrouter'))->toHaveKey('anthropic/claude-opus-5.5')
        ->and(array_unique(catalogSources($result, 'openrouter'), SORT_REGULAR))->toBe(['anthropic/claude-opus-5.5' => PricingSource::OpenRouter])
        ->and($result->sourceStatuses)->toBe([
            PricingCatalog::SOURCE_OPENROUTER => 'ok',
            PricingCatalog::SOURCE_XAI => 'disabled',
            PricingCatalog::SOURCE_MODELS_DEV => 'ok',
            PricingCatalog::SOURCE_LITELLM => 'disabled',
        ])
        ->and($result->unavailable)->toBeFalse();
});

test('a failed openrouter api falls back to the models.dev openrouter slice', function (): void {
    catalogEnable('models_dev', 'openrouter');
    catalogFake([
        'models.dev/*' => 'tests/Fixtures/ModelsDev/api.json',
        'openrouter.ai/*' => 503,
    ]);

    $result = resolve(PricingCatalog::class)->fetch(RefreshScope::all());

    expect(catalogSources($result, 'openrouter'))->toBe(['openai/gpt-4o' => PricingSource::ModelsDev])
        ->and($result->sourceStatuses[PricingCatalog::SOURCE_OPENROUTER])->toBe('server_error')
        ->and($result->unavailable)->toBeFalse();
});

test('xai api prices xai rows when a key is configured', function (): void {
    catalogEnable('xai');
    catalogFake(['api.x.ai/*' => 'tests/Fixtures/Xai/language-models.json']);

    $result = resolve(PricingCatalog::class)->fetch(RefreshScope::all());

    expect(catalogSources($result, 'xai'))->toMatchArray(['grok-5' => PricingSource::XaiApi]);
});

test('xai without a key is not configured and falls back to the feeds', function (): void {
    config()->set('ai.providers.xai.key', null);
    catalogEnable('xai', 'litellm');
    catalogFake(['raw.githubusercontent.com/*' => 'tests/Fixtures/LiteLlm/prices.json']);

    $result = resolve(PricingCatalog::class)->fetch(RefreshScope::all());

    expect($result->sourceStatuses[PricingCatalog::SOURCE_XAI])->toBe('not_configured')
        ->and(catalogSources($result, 'xai'))->toBe(['grok-5' => PricingSource::LiteLlm])
        ->and($result->errorMessage)->toBeNull();
});

test('models.dev and litellm are reconciled for direct providers', function (): void {
    catalogEnable('models_dev', 'litellm');
    Http::fake([
        'models.dev/*' => Http::response(json_encode([
            'anthropic' => ['models' => [
                'claude-opus-5-5' => ['cost' => ['input' => 4, 'output' => 20], 'modalities' => ['output' => ['text']]],
                'claude-conflict' => ['cost' => ['input' => 1, 'output' => 5], 'modalities' => ['output' => ['text']]],
            ]],
        ], JSON_THROW_ON_ERROR)),
        'raw.githubusercontent.com/*' => Http::response(json_encode([
            'claude-opus-5-5' => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => 4.0e-06, 'output_cost_per_token' => 2.0e-05],
            'claude-conflict' => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => 2.0e-06, 'output_cost_per_token' => 5.0e-06],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $result = resolve(PricingCatalog::class)->fetch(RefreshScope::forProviders(['anthropic']));

    expect(catalogSources($result, 'anthropic'))->toBe(['claude-opus-5-5' => PricingSource::FeedConsensus])
        ->and($result->providers['anthropic']->conflicts)->toBe(['claude-conflict']);
});

test('every enabled source failing makes the catalog unavailable', function (): void {
    catalogEnable('models_dev', 'litellm');
    catalogFake([
        'models.dev/*' => 500,
        'raw.githubusercontent.com/*' => 404,
    ]);

    $result = resolve(PricingCatalog::class)->fetch(RefreshScope::all());

    expect($result->unavailable)->toBeTrue()
        ->and($result->providers)->toBe([])
        ->and($result->errorMessage)->toBe('Models.dev pricing API responded with HTTP 500.');
});

test('models.dev only mode ignores the other sources and the models.dev gate', function (): void {
    catalogEnable('litellm', 'openrouter');
    catalogFake(['models.dev/*' => 'tests/Fixtures/ModelsDev/api.json']);

    $result = resolve(PricingCatalog::class)->fetch(RefreshScope::all(), modelsDevOnly: true);

    expect($result->sourceStatuses)->toBe([
        PricingCatalog::SOURCE_OPENROUTER => 'disabled',
        PricingCatalog::SOURCE_XAI => 'disabled',
        PricingCatalog::SOURCE_MODELS_DEV => 'ok',
        PricingCatalog::SOURCE_LITELLM => 'disabled',
    ])
        ->and(array_unique(array_merge(...array_map(
            static fn ($providerPricingResult): array => array_map(static fn (ModelPriceCandidate $modelPriceCandidate): string => $modelPriceCandidate->source->value, $providerPricingResult->candidates),
            array_values($result->providers),
        ))))->toBe(['models_dev']);

    Http::assertSentCount(1);
});
