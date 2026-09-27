<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\PricingReconciler;

/**
 * @param  array<string, string|null>  $rates  column => value (null = missing)
 */
function reconcilerCandidate(string $model, PricingSource $pricingSource, array $rates, bool $tiered = false): ModelPriceCandidate
{
    $fields = [];

    foreach (['input_per_mtok', 'output_per_mtok', 'cache_read_per_mtok', 'cache_write_per_mtok', 'reasoning_per_mtok'] as $column) {
        $value = $rates[$column] ?? null;
        $fields[$column] = $value === null ? CandidatePriceField::missing() : CandidatePriceField::of($value);
    }

    return new ModelPriceCandidate(
        provider: 'openai',
        model: $model,
        fields: $fields,
        source: $pricingSource,
        sourceUrl: $pricingSource === PricingSource::ModelsDev ? 'https://models.dev/api.json' : 'https://litellm.example/prices.json',
        tiered: $tiered,
    );
}

/**
 * @param  list<ModelPriceCandidate>  $candidates
 * @param  list<PricingRejection>  $rejections
 */
function reconcilerResult(array $candidates, array $rejections = [], array $warnings = []): ProviderPricingResult
{
    return new ProviderPricingResult(provider: 'openai', candidates: $candidates, rejections: $rejections, warnings: $warnings);
}

function reconcilerFind(ProviderPricingResult $providerPricingResult, string $model): ?ModelPriceCandidate
{
    foreach ($providerPricingResult->candidates as $candidate) {
        if ($candidate->model === $model) {
            return $candidate;
        }
    }

    return null;
}

test('agreeing primary rates produce one consensus candidate', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::ModelsDev, ['input_per_mtok' => '2.5', 'output_per_mtok' => '10'])]),
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::LiteLlm, ['input_per_mtok' => '2.5', 'output_per_mtok' => '10'])]),
    );

    $candidate = reconcilerFind($result, 'gpt-a');

    expect($result->candidates)->toHaveCount(1)
        ->and($candidate->source)->toBe(PricingSource::FeedConsensus)
        ->and($candidate->sourceUrl)->toBe('https://models.dev/api.json')
        ->and($result->conflicts)->toBe([]);
});

test('treats values equal at four decimals as agreement', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::ModelsDev, ['input_per_mtok' => '2.5', 'output_per_mtok' => '10'])]),
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::LiteLlm, ['input_per_mtok' => '2.50000', 'output_per_mtok' => '10.00001'])]),
    );

    expect(reconcilerFind($result, 'gpt-a')?->source)->toBe(PricingSource::FeedConsensus);
});

test('disagreeing primary rates become a conflict with no candidate', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::ModelsDev, ['input_per_mtok' => '2.5', 'output_per_mtok' => '10'])]),
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::LiteLlm, ['input_per_mtok' => '3', 'output_per_mtok' => '10'])]),
    );

    expect($result->candidates)->toBe([])
        ->and($result->conflicts)->toBe(['gpt-a']);
});

test('a model only one feed knows is written from that feed', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([reconcilerCandidate('only-md', PricingSource::ModelsDev, ['input_per_mtok' => '1', 'output_per_mtok' => '2'])]),
        reconcilerResult([reconcilerCandidate('only-ll', PricingSource::LiteLlm, ['input_per_mtok' => '1', 'output_per_mtok' => '2'])]),
    );

    expect(reconcilerFind($result, 'only-md')?->source)->toBe(PricingSource::ModelsDev)
        ->and(reconcilerFind($result, 'only-ll')?->source)->toBe(PricingSource::LiteLlm);
});

test('optional rates prefer models.dev, fall back to litellm, and warn on mismatch', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::ModelsDev, ['input_per_mtok' => '1', 'output_per_mtok' => '2', 'cache_read_per_mtok' => '0.1'])]),
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::LiteLlm, ['input_per_mtok' => '1', 'output_per_mtok' => '2', 'cache_read_per_mtok' => '0.2', 'reasoning_per_mtok' => '4'])]),
    );

    $candidate = reconcilerFind($result, 'gpt-a');

    expect($candidate->fields['cache_read_per_mtok']->value)->toBe('0.1')
        ->and($candidate->fields['reasoning_per_mtok']->value)->toBe('4')
        ->and($candidate->fields['cache_write_per_mtok']->supplied)->toBeFalse()
        ->and(array_map(fn (PricingWarning $pricingWarning): array => [$pricingWarning->code, $pricingWarning->detail], $result->warnings))
        ->toBe([[PricingWarning::RATE_MISMATCH, 'cache_read_per_mtok']]);
});

test('a model one feed rejects but the other accepts is written without a rejection', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([], [new PricingRejection('openai', 'gpt-a', PricingRejection::MISSING_COST)]),
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::LiteLlm, ['input_per_mtok' => '1', 'output_per_mtok' => '2'])]),
    );

    expect(reconcilerFind($result, 'gpt-a')?->source)->toBe(PricingSource::LiteLlm)
        ->and($result->rejections)->toBe([]);
});

test('a model both feeds reject is counted once', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([], [new PricingRejection('openai', 'old', PricingRejection::DEPRECATED)]),
        reconcilerResult([], [new PricingRejection('openai', 'old', PricingRejection::DEPRECATED)]),
    );

    expect($result->rejections)->toHaveCount(1);
});

test('tiering from either feed carries onto the consensus candidate', function (): void {
    $result = new PricingReconciler()->reconcile(
        'openai',
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::ModelsDev, ['input_per_mtok' => '1', 'output_per_mtok' => '2'])]),
        reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::LiteLlm, ['input_per_mtok' => '1', 'output_per_mtok' => '2'], tiered: true)]),
    );

    expect(reconcilerFind($result, 'gpt-a')?->tiered)->toBeTrue();
});

test('a single available feed passes through untouched', function (): void {
    $modelsDev = reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::ModelsDev, ['input_per_mtok' => '1', 'output_per_mtok' => '2'])]);

    expect(new PricingReconciler()->reconcile('openai', $modelsDev, null))->toBe($modelsDev)
        ->and(new PricingReconciler()->reconcile('openai', null, null))->toBeNull();
});

test('a malformed feed is ignored when the other feed is usable', function (): void {
    $malformed = new ProviderPricingResult(provider: 'openai', candidates: [], rejections: [new PricingRejection('openai', '', PricingRejection::MALFORMED_PROVIDER)]);
    $liteLlm = reconcilerResult([reconcilerCandidate('gpt-a', PricingSource::LiteLlm, ['input_per_mtok' => '1', 'output_per_mtok' => '2'])]);

    expect(new PricingReconciler()->reconcile('openai', $malformed, $liteLlm))->toBe($liteLlm)
        ->and(new PricingReconciler()->reconcile('openai', $malformed, null))->toBe($malformed);
});
