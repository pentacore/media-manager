<?php

declare(strict_types=1);

use App\Settings\AiSettings;

test('models.dev pricing enabled falls back to the config default when unset', function (): void {
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    expect(resolve(AiSettings::class)->modelsDevPricingEnabled())->toBeTrue();

    config()->set('mediamanager.ai.pricing.models_dev.enabled', false);
    expect(resolve(AiSettings::class)->modelsDevPricingEnabled())->toBeFalse();
});

test('a saved models.dev pricing flag overrides the config default', function (): void {
    // Config default is ON; the persisted setting must win.
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);

    resolve(AiSettings::class)->setModelsDevPricingEnabled(false);

    expect(resolve(AiSettings::class)->modelsDevPricingEnabled())->toBeFalse();
});

test('clearing the models.dev pricing flag restores the config default', function (): void {
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setModelsDevPricingEnabled(false);

    expect($aiSettings->modelsDevPricingEnabled())->toBeFalse();

    $aiSettings->setModelsDevPricingEnabled(null);
    expect($aiSettings->modelsDevPricingEnabled())->toBeTrue();
});

test('ignored pricing providers fall back to the config default when unset', function (): void {
    config()->set('mediamanager.ai.pricing.ignored_providers', ['groq']);

    expect(resolve(AiSettings::class)->ignoredPricingProviders())->toBe(['groq']);
});

test('a saved ignored pricing provider list overrides and round-trips normalized', function (): void {
    config()->set('mediamanager.ai.pricing.ignored_providers', ['groq']);

    resolve(AiSettings::class)->setIgnoredPricingProviders(['Cohere', ' openrouter ', '']);

    // Lowercased, trimmed, empties dropped, and independent of the config default.
    expect(resolve(AiSettings::class)->ignoredPricingProviders())->toBe(['cohere', 'openrouter']);
});

test('a saved empty ignore list overrides a non-empty config default', function (): void {
    config()->set('mediamanager.ai.pricing.ignored_providers', ['groq']);

    resolve(AiSettings::class)->setIgnoredPricingProviders([]);

    expect(resolve(AiSettings::class)->ignoredPricingProviders())->toBe([]);
});
