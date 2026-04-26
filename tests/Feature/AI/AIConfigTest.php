<?php

declare(strict_types=1);
use App\Providers\AIServiceProvider;

test('ai feature is disabled by default', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    expect(config('mediamanager.ai.enabled'))->toBeFalse();
});

test('ai feature can be enabled via config', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    expect(config('mediamanager.ai.enabled'))->toBeTrue();
});

test('default ai provider is set to anthropic', function (): void {
    expect(config('ai.default'))->toBe('anthropic');
});

test('anthropic provider configuration is present', function (): void {
    expect(config('ai.providers.anthropic'))->toBeArray();
    expect(config('ai.providers.anthropic.driver'))->toBe('anthropic');
});

test('default per-agent models are set', function (): void {
    expect(config('mediamanager.ai.command_model'))->toBe('gpt-5-mini');
    expect(config('mediamanager.ai.advisor_model'))->toBe('gpt-5-mini');
    expect(config('mediamanager.ai.mode'))->toBe('executive');
});

test('AIServiceProvider is registered', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AIServiceProvider::class);
});
