<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\OpenRouterPricingAdapter;
use App\Services\AiUsage\Pricing\RefreshScope;

/**
 * @return list<mixed>
 */
function openRouterAdapterFixture(): array
{
    /** @var array{data: list<mixed>} $decoded */
    $decoded = json_decode((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json')), true);

    return $decoded['data'];
}

function openRouterAdapterCandidate(ProviderPricingResult $providerPricingResult, string $model): ?ModelPriceCandidate
{
    foreach ($providerPricingResult->candidates as $candidate) {
        if ($candidate->model === $model) {
            return $candidate;
        }
    }

    return null;
}

function openRouterAdapterRejection(ProviderPricingResult $providerPricingResult, string $model): ?PricingRejection
{
    foreach ($providerPricingResult->rejections as $rejection) {
        if ($rejection->model === $model) {
            return $rejection;
        }
    }

    return null;
}

test('per-token prices become per-million rates for the openrouter provider', function (): void {
    $result = new OpenRouterPricingAdapter()->adapt(openRouterAdapterFixture(), RefreshScope::all());

    $candidate = openRouterAdapterCandidate($result, 'anthropic/claude-opus-5.5');

    expect($result->provider)->toBe('openrouter')
        ->and($candidate)->not->toBeNull()
        ->and($candidate->provider)->toBe('openrouter')
        ->and($candidate->source)->toBe(PricingSource::OpenRouter)
        ->and($candidate->sourceUrl)->toBe('https://openrouter.ai/api/v1/models')
        ->and($candidate->fields['input_per_mtok']->value)->toBe('4')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('20')
        ->and($candidate->fields['cache_read_per_mtok']->value)->toBe('0.2')
        ->and($candidate->fields['cache_write_per_mtok']->value)->toBe('5')
        ->and($candidate->fields['reasoning_per_mtok']->supplied)->toBeFalse()
        ->and($candidate->tiered)->toBeFalse();
});

test('long-context overrides flag the candidate as tiered without changing the base rate', function (): void {
    $result = new OpenRouterPricingAdapter()->adapt(openRouterAdapterFixture(), RefreshScope::all());

    $candidate = openRouterAdapterCandidate($result, 'openai/gpt-6-luna');

    expect($candidate->tiered)->toBeTrue()
        ->and($candidate->fields['input_per_mtok']->value)->toBe('0.1')
        ->and($candidate->fields['reasoning_per_mtok']->supplied)->toBeTrue()
        ->and($candidate->fields['reasoning_per_mtok']->value)->toBe('0')
        ->and(array_map(fn (PricingWarning $pricingWarning): string => $pricingWarning->code, $result->warnings))->toBe([PricingWarning::CONTEXT_TIERS]);
});

test('variant ids, non-text models, variable prices and incomplete entries are rejected', function (string $model, string $code): void {
    $result = new OpenRouterPricingAdapter()->adapt(openRouterAdapterFixture(), RefreshScope::all());

    expect(openRouterAdapterCandidate($result, $model))->toBeNull()
        ->and(openRouterAdapterRejection($result, $model)?->code)->toBe($code);
})->with([
    'batch variant' => ['anthropic/claude-opus-5.5:batch', PricingRejection::VARIANT],
    'free variant' => ['meta-llama/llama-5-8b:free', PricingRejection::VARIANT],
    'image output' => ['google/imagen-5', PricingRejection::NON_TEXT_OUTPUT],
    'variable router price' => ['openrouter/auto', PricingRejection::INVALID_COST],
    'missing completion' => ['vendor/no-completion', PricingRejection::MISSING_OUTPUT],
]);

test('a non-object entry is rejected as an invalid identifier', function (): void {
    $result = new OpenRouterPricingAdapter()->adapt(openRouterAdapterFixture(), RefreshScope::all());

    expect(openRouterAdapterRejection($result, '')?->code)->toBe(PricingRejection::INVALID_IDENTIFIER);
});

test('the adapter returns nothing when the scope excludes openrouter', function (): void {
    expect(new OpenRouterPricingAdapter()->adapt(openRouterAdapterFixture(), RefreshScope::forProviders(['openai'])))->toBeNull();
});

test('a model-pinned scope only yields the pinned openrouter models', function (): void {
    $result = new OpenRouterPricingAdapter()->adapt(
        openRouterAdapterFixture(),
        RefreshScope::forProviderModels(['openrouter' => ['openai/gpt-6-luna']]),
    );

    expect(array_map(fn (ModelPriceCandidate $modelPriceCandidate): string => $modelPriceCandidate->model, $result->candidates))
        ->toBe(['openai/gpt-6-luna']);
});

test('openrouter creation is suppressed while it is off the auto-create list', function (): void {
    $result = new OpenRouterPricingAdapter()->adapt(openRouterAdapterFixture(), RefreshScope::all());

    expect($result->createSuppressed)->toBeTrue();
});
