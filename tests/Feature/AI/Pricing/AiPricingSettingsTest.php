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

test('auto-create pricing providers default to every provider except openrouter', function (): void {
    expect(resolve(AiSettings::class)->autoCreatePricingProviders())
        ->toBe(['openai', 'anthropic', 'gemini', 'xai', 'deepseek', 'mistral', 'groq', 'cohere']);
});

test('auto-create pricing providers fall back to the config default when unset', function (): void {
    config()->set('mediamanager.ai.pricing.auto_create_providers', ['openai']);

    expect(resolve(AiSettings::class)->autoCreatePricingProviders())->toBe(['openai']);
});

test('a saved auto-create provider list overrides and round-trips normalized', function (): void {
    resolve(AiSettings::class)->setAutoCreatePricingProviders(['OpenRouter', ' groq ', '']);

    expect(resolve(AiSettings::class)->autoCreatePricingProviders())->toBe(['openrouter', 'groq']);
});

test('a saved empty auto-create list makes every provider update-only', function (): void {
    resolve(AiSettings::class)->setAutoCreatePricingProviders([]);

    expect(resolve(AiSettings::class)->autoCreatePricingProviders())->toBe([]);
});

test('clearing the auto-create list restores the config default', function (): void {
    config()->set('mediamanager.ai.pricing.auto_create_providers', ['openai']);
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setAutoCreatePricingProviders([]);
    $aiSettings->setAutoCreatePricingProviders(null);

    expect($aiSettings->autoCreatePricingProviders())->toBe(['openai']);
});

test('the price updater model follows the chat model when nothing is configured', function (): void {
    config()->set('mediamanager.ai.pricing.updater_model', '');
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModel('gpt-chat');

    expect($aiSettings->rawPriceUpdaterModel())->toBeNull()
        ->and($aiSettings->priceUpdaterModel())->toBe('gpt-chat');
});

test('the price updater model falls back to the config default when unset', function (): void {
    config()->set('mediamanager.ai.pricing.updater_model', 'gpt-config-updater');

    expect(resolve(AiSettings::class)->priceUpdaterModel())->toBe('gpt-config-updater');
});

test('a saved price updater model overrides the config default and clears back to it', function (): void {
    config()->set('mediamanager.ai.pricing.updater_model', 'gpt-config-updater');
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setPriceUpdaterModel('gpt-saved-updater');

    expect($aiSettings->priceUpdaterModel())->toBe('gpt-saved-updater')
        ->and($aiSettings->rawPriceUpdaterModel())->toBe('gpt-saved-updater');

    $aiSettings->setPriceUpdaterModel(null);

    expect($aiSettings->priceUpdaterModel())->toBe('gpt-config-updater');
});
