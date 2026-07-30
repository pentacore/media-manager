<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\RefreshScope;
use App\Settings\AiSettings;

test('forTargets binds exact models per provider without widening', function (): void {
    $refreshScope = RefreshScope::forTargets([
        'anthropic' => null,                 // whole-provider wildcard
        'openai' => ['gpt-anom'],            // exact model only
    ]);

    // Anthropic is opened wide: any model is allowed.
    expect($refreshScope->allowsWrite('anthropic', 'claude-sonnet-4-6'))->toBeTrue()
        ->and($refreshScope->allowsWrite('anthropic', 'claude-anything'))->toBeTrue();

    // OpenAI stays pinned to its one anomalous model — a whole-provider
    // fallback elsewhere must NOT widen it.
    expect($refreshScope->allowsWrite('openai', 'gpt-anom'))->toBeTrue()
        ->and($refreshScope->allowsWrite('openai', 'gpt-5-mini'))->toBeFalse();

    // A provider outside the target map is denied entirely.
    expect($refreshScope->allowsWrite('gemini', 'gemini-2.5-pro'))->toBeFalse()
        ->and($refreshScope->allowsProvider('gemini'))->toBeFalse();
});

test('forTargets canonicalizes upstream provider spellings', function (): void {
    $refreshScope = RefreshScope::forTargets(['google' => ['gemini-2.5-pro']]);

    expect($refreshScope->allowsWrite('google', 'gemini-2.5-pro'))->toBeTrue()
        ->and($refreshScope->allowsWrite('gemini', 'gemini-2.5-pro'))->toBeTrue()
        ->and($refreshScope->modelsFor('gemini'))->toBe(['gemini-2.5-pro']);
});

test('a wildcard target wins when the same canonical provider is also listed exactly', function (): void {
    // `google` and `gemini` both canonicalize to `gemini`; the wildcard entry
    // must keep the provider open rather than being narrowed by the list entry.
    $refreshScope = RefreshScope::forTargets([
        'google' => null,
        'gemini' => ['gemini-2.5-pro'],
    ]);

    expect($refreshScope->allowsWrite('gemini', 'gemini-2.5-pro'))->toBeTrue()
        ->and($refreshScope->allowsWrite('gemini', 'gemini-2.5-flash'))->toBeTrue()
        ->and($refreshScope->modelsFor('gemini'))->toBeNull();
});

test('modelsFor returns null for a provider-level wildcard target', function (): void {
    $refreshScope = RefreshScope::forTargets(['anthropic' => null]);

    expect($refreshScope->modelsFor('anthropic'))->toBeNull()
        ->and($refreshScope->isBounded())->toBeTrue();
});

test('a provider saved to the runtime ignore setting is treated as unsupported', function (): void {
    resolve(AiSettings::class)->setIgnoredPricingProviders(['groq']);

    $refreshScope = RefreshScope::all();

    expect(RefreshScope::canonicalProvider('groq'))->toBeNull()
        ->and($refreshScope->allowsProvider('groq'))->toBeFalse()
        ->and($refreshScope->allowsWrite('groq', 'llama-3.1-8b'))->toBeFalse()
        // Providers outside the ignore list stay supported.
        ->and($refreshScope->allowsProvider('openai'))->toBeTrue();
});

test('the runtime ignore list falls back to the config default when no setting is saved', function (): void {
    config()->set('mediamanager.ai.pricing.ignored_providers', ['cohere']);

    $refreshScope = RefreshScope::all();

    expect($refreshScope->allowsProvider('cohere'))->toBeFalse()
        ->and($refreshScope->allowsProvider('openai'))->toBeTrue();
});

test('a saved ignore list overrides the config default', function (): void {
    // Config ignores cohere, but the saved setting ignores groq instead.
    config()->set('mediamanager.ai.pricing.ignored_providers', ['cohere']);
    resolve(AiSettings::class)->setIgnoredPricingProviders(['groq']);

    $refreshScope = RefreshScope::all();

    expect($refreshScope->allowsProvider('groq'))->toBeFalse()
        ->and($refreshScope->allowsProvider('cohere'))->toBeTrue();
});
