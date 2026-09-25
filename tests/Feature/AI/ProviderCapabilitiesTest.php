<?php

declare(strict_types=1);

use App\Ai\ProviderCapabilities;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Enums\Lab;

test('a single OpenAI chain supports tool search', function (): void {
    config()->set('ai.default', 'openai');
    resolve(AiSettings::class)->setFailoverProvider(null);

    expect(resolve(ProviderCapabilities::class)->chain())->toBe([Lab::OpenAI])
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class))->toBeTrue();
});

test('a chain failing over to Gemini does not support tool search', function (): void {
    config()->set('ai.default', 'openai');
    resolve(AiSettings::class)->setFailoverProvider(Lab::Gemini);

    expect(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class))->toBeFalse()
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsCodeExecution::class))->toBeTrue();
});

test('an OpenAI to Anthropic chain supports tool search', function (): void {
    config()->set('ai.default', 'openai');
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);

    expect(resolve(ProviderCapabilities::class)->chain())->toBe([Lab::OpenAI, Lab::Anthropic])
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class))->toBeTrue();
});

test('a provider that cannot be resolved counts as unsupported', function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.anthropic.driver', 'not-a-driver');
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);

    expect(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class))->toBeFalse();
});
