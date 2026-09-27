<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\LiteLlmPricingAdapter;
use App\Services\AiUsage\Pricing\RefreshScope;

/**
 * @return array<string, mixed>
 */
function liteLlmAdapterFixture(): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents(base_path('tests/Fixtures/LiteLlm/prices.json')), true);

    return $decoded;
}

/**
 * @return array<string, ProviderPricingResult>
 */
function liteLlmAdapterResults(?RefreshScope $refreshScope = null): array
{
    return new LiteLlmPricingAdapter()->adapt(liteLlmAdapterFixture(), $refreshScope ?? RefreshScope::all());
}

function liteLlmAdapterCandidate(ProviderPricingResult $providerPricingResult, string $model): ?ModelPriceCandidate
{
    foreach ($providerPricingResult->candidates as $candidate) {
        if ($candidate->model === $model) {
            return $candidate;
        }
    }

    return null;
}

function liteLlmAdapterRejectionCode(ProviderPricingResult $providerPricingResult, string $model): ?string
{
    foreach ($providerPricingResult->rejections as $rejection) {
        if ($rejection->model === $model) {
            return $rejection->code;
        }
    }

    return null;
}

test('scientific-notation per-token costs become exact per-million rates', function (): void {
    $candidate = liteLlmAdapterCandidate(liteLlmAdapterResults()['anthropic'], 'claude-opus-5-5');

    expect($candidate)->not->toBeNull()
        ->and($candidate->source)->toBe(PricingSource::LiteLlm)
        ->and($candidate->fields['input_per_mtok']->value)->toBe('4')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('20')
        ->and($candidate->fields['cache_read_per_mtok']->value)->toBe('0.2')
        ->and($candidate->fields['cache_write_per_mtok']->value)->toBe('5')
        ->and($candidate->fields['reasoning_per_mtok']->supplied)->toBeFalse();
});

test('provider prefixes are stripped and duplicate keys collapse to one model', function (): void {
    $results = liteLlmAdapterResults();

    $deepseekModels = array_map(fn (ModelPriceCandidate $modelPriceCandidate): string => $modelPriceCandidate->model, $results['deepseek']->candidates);

    expect(liteLlmAdapterCandidate($results['gemini'], 'gemini-3-pro'))->not->toBeNull()
        ->and($deepseekModels)->toBe(['deepseek-chat'])
        ->and(liteLlmAdapterCandidate($results['xai'], 'grok-5')?->fields['reasoning_per_mtok']->value)->toBe('15');
});

test('duplicate keys with different prices reject the model', function (): void {
    $results = liteLlmAdapterResults();

    expect(liteLlmAdapterCandidate($results['mistral'], 'mistral-dup'))->toBeNull()
        ->and(liteLlmAdapterRejectionCode($results['mistral'], 'mistral-dup'))->toBe(PricingRejection::INVALID_COST);
});

test('cohere chat entries map to the cohere provider', function (): void {
    expect(liteLlmAdapterCandidate(liteLlmAdapterResults()['cohere'], 'command-a-03-2025'))->not->toBeNull();
});

test('a numeric model id reaches the writer as a string', function (): void {
    $candidate = liteLlmAdapterCandidate(liteLlmAdapterResults()['groq'], '42');

    expect($candidate)->not->toBeNull()
        ->and($candidate->model)->toBe('42')
        ->and($candidate->fields['input_per_mtok']->value)->toBe('0.05');
});

test('above-threshold token costs flag the candidate as tiered', function (): void {
    expect(liteLlmAdapterCandidate(liteLlmAdapterResults()['gemini'], 'gemini-3-pro')?->tiered)->toBeTrue();
});

test('unusable entries are rejected with stable codes', function (string $provider, string $model, string $code): void {
    expect(liteLlmAdapterRejectionCode(liteLlmAdapterResults()[$provider], $model))->toBe($code);
})->with([
    'dated snapshot of a known base' => ['anthropic', 'claude-opus-5-5-20260901', PricingRejection::DATED_VARIANT],
    'past deprecation date' => ['anthropic', 'claude-retired', PricingRejection::DEPRECATED],
    'embedding mode' => ['gemini', 'gemini-embedding-001', PricingRejection::NON_TEXT_OUTPUT],
    'missing output cost' => ['openai', 'no-output-cost', PricingRejection::MISSING_OUTPUT],
]);

test('fine-tunes, the sample spec, openrouter and vertex entries are ignored', function (): void {
    $results = liteLlmAdapterResults();

    expect($results)->not->toHaveKey('openrouter')
        ->and($results)->not->toHaveKey('vertex_ai-language-models')
        ->and(liteLlmAdapterCandidate($results['openai'], 'ft:gpt-4o'))->toBeNull()
        ->and(liteLlmAdapterRejectionCode($results['openai'], 'ft:gpt-4o'))->toBeNull();
});

test('only providers allowed by the scope are returned', function (): void {
    expect(array_keys(liteLlmAdapterResults(RefreshScope::forProviders(['anthropic']))))->toBe(['anthropic']);
});
