<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
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
]);

test('the adapter returns nothing when the scope excludes xai', function (): void {
    expect(new XaiPricingAdapter()->adapt(xaiAdapterFixture(), RefreshScope::forProviders(['openai'])))->toBeNull();
});
