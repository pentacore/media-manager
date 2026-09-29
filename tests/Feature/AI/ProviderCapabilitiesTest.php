<?php

declare(strict_types=1);

use App\Ai\ModelSelection;
use App\Ai\ProviderCapabilities;
use App\Ai\Routing\ToolPayload;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Enums\Lab;

test('a single OpenAI selection supports tool search', function (): void {
    resolve(AiSettings::class)->setFailoverProvider(null);
    $selection = new ModelSelection('openai', 'gpt-5-mini');

    expect(resolve(ProviderCapabilities::class)->chain($selection))->toBe(['openai'])
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, $selection))->toBeTrue();
});

test('an OpenRouter selection supports neither tool search nor code execution', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    $selection = new ModelSelection('openrouter', 'anthropic/claude-sonnet-5');

    expect(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, $selection))->toBeFalse()
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsCodeExecution::class, $selection))->toBeFalse();
});

test('a selection failing over to Gemini does not support tool search', function (): void {
    resolve(AiSettings::class)->setFailoverProvider(Lab::Gemini);
    $selection = new ModelSelection('openai', 'gpt-5-mini');

    expect(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, $selection))->toBeFalse()
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsCodeExecution::class, $selection))->toBeTrue();
});

test('an OpenAI to Anthropic chain supports tool search', function (): void {
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);
    $selection = new ModelSelection('openai', 'gpt-5-mini');

    expect(resolve(ProviderCapabilities::class)->chain($selection))->toBe(['openai', 'anthropic'])
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, $selection))->toBeTrue();
});

test('a provider that cannot be resolved counts as unsupported', function (): void {
    config()->set('ai.providers.anthropic.driver', 'not-a-driver');
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);

    expect(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, new ModelSelection('openai', 'gpt-5-mini')))->toBeFalse();
});

test('tool search registration follows the chat selection', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider('openrouter');
    $aiSettings->setModel('anthropic/claude-sonnet-5');

    $tools = [new stdClass, new stdClass];

    expect(resolve(ToolPayload::class)->build($tools))->toBe($tools);
});
