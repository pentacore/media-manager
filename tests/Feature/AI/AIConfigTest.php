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

test('default ai provider falls back to openai when AI_DEFAULT_PROVIDER env is unset', function (): void {
    expect(config('ai.default'))->toBe('openai');
});

test('anthropic provider configuration is still present even when not the default', function (): void {
    expect(config('ai.providers.anthropic'))->toBeArray();
    expect(config('ai.providers.anthropic.driver'))->toBe('anthropic');
});

test('default ai model is set', function (): void {
    expect(config('mediamanager.ai.model'))->toBe('gpt-5-mini');
    expect(config('mediamanager.ai.mode'))->toBe('executive');
});

test('AIServiceProvider is registered', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AIServiceProvider::class);
});
