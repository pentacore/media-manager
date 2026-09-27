<?php

declare(strict_types=1);

use App\Enums\PricingSource;

test('every pricing source has its admin label', function (PricingSource $pricingSource, string $label): void {
    expect($pricingSource->label())->toBe($label);
})->with([
    'seed' => [PricingSource::Seed, 'Seed data'],
    'models.dev' => [PricingSource::ModelsDev, 'Models.dev'],
    'first party' => [PricingSource::FirstParty, 'First-party source'],
    'manual' => [PricingSource::Manual, 'Manual'],
    'legacy' => [PricingSource::Legacy, 'Legacy'],
    'openrouter' => [PricingSource::OpenRouter, 'OpenRouter'],
    'litellm' => [PricingSource::LiteLlm, 'LiteLLM'],
    'xai api' => [PricingSource::XaiApi, 'xAI API'],
    'feed consensus' => [PricingSource::FeedConsensus, 'Models.dev + LiteLLM'],
]);

test('new pricing sources persist with snake case values', function (): void {
    expect(PricingSource::OpenRouter->value)->toBe('openrouter')
        ->and(PricingSource::LiteLlm->value)->toBe('litellm')
        ->and(PricingSource::XaiApi->value)->toBe('xai_api')
        ->and(PricingSource::FeedConsensus->value)->toBe('feed_consensus');
});

test('only the xai pricing api counts as a first-party api source', function (): void {
    $firstParty = array_values(array_filter(
        PricingSource::cases(),
        static fn (PricingSource $pricingSource): bool => $pricingSource->isFirstPartyApi(),
    ));

    expect($firstParty)->toBe([PricingSource::XaiApi]);
});
