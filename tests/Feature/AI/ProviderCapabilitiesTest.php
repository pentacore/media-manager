<?php

declare(strict_types=1);

use App\Ai\ModelSelection;
use App\Ai\ProviderCapabilities;
use App\Ai\Routing\ToolPayload;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Enums\Lab;

test('a single OpenAI selection supports tool search', function (): void {
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
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Gemini->value])->create();
    $selection = new ModelSelection('openai', 'gpt-5-mini');

    expect(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, $selection))->toBeFalse()
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsCodeExecution::class, $selection))->toBeTrue();
});

test('an OpenAI to Anthropic chain supports tool search', function (): void {
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();
    $selection = new ModelSelection('openai', 'gpt-5-mini');

    expect(resolve(ProviderCapabilities::class)->chain($selection))->toBe(['openai', 'anthropic'])
        ->and(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, $selection))->toBeTrue();
});

test('a provider that cannot be resolved counts as unsupported', function (): void {
    config()->set('ai.providers.anthropic.driver', 'not-a-driver');
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();

    expect(resolve(ProviderCapabilities::class)->everyProviderSupports(SupportsToolSearch::class, new ModelSelection('openai', 'gpt-5-mini')))->toBeFalse();
});

test('tool search registration follows the chat selection', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'anthropic/claude-sonnet-5')->create();

    $tools = [new stdClass, new stdClass];

    expect(resolve(ToolPayload::class)->build($tools))->toBe($tools);
});
