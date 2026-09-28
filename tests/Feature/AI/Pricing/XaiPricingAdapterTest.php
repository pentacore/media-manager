<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\RefreshScope;
use App\Services\AiUsage\Pricing\XaiPricingAdapter;

/**
 * @return list<mixed>
 */
function xaiAdapterFixture(): array
{
    /** @var array{models: list<mixed>} $decoded */
    $decoded = json_decode((string) file_get_contents(base_path('tests/Fixtures/Xai/language-models.json')), true);

    return $decoded['models'];
}

function xaiAdapterCandidate(ProviderPricingResult $providerPricingResult, string $model): ?ModelPriceCandidate
{
    foreach ($providerPricingResult->candidates as $candidate) {
        if ($candidate->model === $model) {
            return $candidate;
        }
    }

    return null;
}

test('cents per hundred million tokens become usd per million', function (): void {
    $result = new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::all());

    $candidate = xaiAdapterCandidate($result, 'grok-5');

    expect($result->provider)->toBe('xai')
        ->and($candidate->source)->toBe(PricingSource::XaiApi)
        ->and($candidate->sourceUrl)->toBe('https://api.x.ai/v1/language-models')
        ->and($candidate->fields['input_per_mtok']->value)->toBe('3')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('15')
        ->and($candidate->fields['cache_read_per_mtok']->value)->toBe('0.75')
        ->and($candidate->fields['cache_write_per_mtok']->supplied)->toBeFalse()
        ->and($candidate->fields['reasoning_per_mtok']->supplied)->toBeFalse()
        ->and($candidate->tiered)->toBeTrue();
});

test('a model without a long-context threshold is not tiered', function (): void {
    $candidate = xaiAdapterCandidate(new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::all()), 'grok-5-mini');

    expect($candidate->tiered)->toBeFalse()
        ->and($candidate->fields['input_per_mtok']->value)->toBe('0.2')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('0.5');
});

test('non-text and incomplete xai models are rejected', function (string $model, string $code): void {
    $result = new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::all());

    $codes = [];

    foreach ($result->rejections as $rejection) {
        $codes[$rejection->model] = $rejection->code;
    }

    expect(xaiAdapterCandidate($result, $model))->toBeNull()
        ->and($codes[$model] ?? null)->toBe($code);
})->with([
    'image output' => ['grok-imagine', PricingRejection::NON_TEXT_OUTPUT],
    'missing completion price' => ['grok-missing-completion', PricingRejection::MISSING_OUTPUT],
    'missing input price' => ['grok-5-no-input', PricingRejection::MISSING_INPUT],
    'negative completion price' => ['grok-5-negative-completion', PricingRejection::INVALID_COST],
]);

test('the adapter returns nothing when the scope excludes xai', function (): void {
    expect(new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::forProviders(['openai'])))->toBeNull();
});

test('a model alias becomes its own candidate with the same fields', function (): void {
    $result = new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::all());

    $canonical = xaiAdapterCandidate($result, 'grok-5');
    $alias = xaiAdapterCandidate($result, 'grok-5-latest');

    expect($alias)->not->toBeNull()
        ->and($alias->source)->toBe(PricingSource::XaiApi)
        ->and($alias->tiered)->toBe($canonical->tiered)
        ->and($alias->fields['input_per_mtok']->value)->toBe($canonical->fields['input_per_mtok']->value)
        ->and($alias->fields['output_per_mtok']->value)->toBe($canonical->fields['output_per_mtok']->value);
});

test('an alias the scope excludes is skipped without a rejection', function (): void {
    $result = new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::forProviderModels(['xai' => ['grok-5']]));

    expect(xaiAdapterCandidate($result, 'grok-5'))->not->toBeNull()
        ->and(xaiAdapterCandidate($result, 'grok-5-latest'))->toBeNull()
        ->and(array_map(fn (PricingRejection $pricingRejection): string => $pricingRejection->model, $result->rejections))->not->toContain('grok-5-latest');
});

test('a long-context rate without a threshold still flags the candidate as tiered', function (): void {
    $result = new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::all());

    $candidate = xaiAdapterCandidate($result, 'grok-5-nano');

    expect($candidate->tiered)->toBeTrue()
        ->and(array_first(array_filter(
            $result->warnings,
            static fn (PricingWarning $pricingWarning): bool => $pricingWarning->model === 'grok-5-nano',
        ))->detail)->toBe('long_context');
});
