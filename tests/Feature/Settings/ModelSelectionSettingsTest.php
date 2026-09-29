<?php

declare(strict_types=1);

use App\Ai\ModelSelection;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.default', 'openai');
    config()->set('mediamanager.ai.model', 'gpt-5-mini');
});

test('a legacy chat model without a saved provider routes to the default provider', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModel('gpt-5-mini');

    expect($aiSettings->chatSelection())->toEqual(new ModelSelection('openai', 'gpt-5-mini'));
});

test('a saved chat provider is returned with the chat model', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider('openrouter');
    $aiSettings->setModel('anthropic/claude-sonnet-5');

    expect($aiSettings->chatSelection())->toEqual(new ModelSelection('openrouter', 'anthropic/claude-sonnet-5'))
        ->and($aiSettings->chatSelection()->toArray())->toBe(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
});

test('clearing the chat provider falls back to the default provider', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider('openrouter');

    $aiSettings->setModelProvider(null);

    expect($aiSettings->modelProvider())->toBe('openai');
});

test('the auto title model resolves to the title provider cheapest model', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setTitleModelProvider('openrouter');
    $aiSettings->setTitleModel(AiSettings::AUTO_MODEL);

    expect($aiSettings->titleSelection())->toEqual(new ModelSelection('openrouter', 'anthropic/claude-haiku-4.5'))
        ->and($aiSettings->titleModel())->toBe('anthropic/claude-haiku-4.5');
});

test('unset sub-agent and price updater selections inherit the whole chat selection', function (): void {
    config()->set('mediamanager.ai.sub_agent_model', '');
    config()->set('mediamanager.ai.pricing.updater_model', '');

    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider('openrouter');
    $aiSettings->setModel('x-ai/grok-4');

    expect($aiSettings->subAgentSelection())->toEqual(new ModelSelection('openrouter', 'x-ai/grok-4'))
        ->and($aiSettings->priceUpdaterSelection())->toEqual(new ModelSelection('openrouter', 'x-ai/grok-4'));
});

test('a sub-agent model saved before providers existed keeps the default provider', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider('openrouter');
    $aiSettings->setSubAgentModel('gpt-5.4-nano');

    expect($aiSettings->subAgentSelection())->toEqual(new ModelSelection('openai', 'gpt-5.4-nano'));
});

test('a saved sub-agent and price updater provider is used', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setSubAgentModel('anthropic/claude-haiku-4.5');
    $aiSettings->setSubAgentModelProvider('openrouter');
    $aiSettings->setPriceUpdaterModel('claude-haiku-4-5');
    $aiSettings->setPriceUpdaterModelProvider('anthropic');

    expect($aiSettings->subAgentSelection())->toEqual(new ModelSelection('openrouter', 'anthropic/claude-haiku-4.5'))
        ->and($aiSettings->priceUpdaterSelection())->toEqual(new ModelSelection('anthropic', 'claude-haiku-4-5'));
});

test('providerChainFor without failover is a single explicit entry', function (): void {
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->providerChainFor(new ModelSelection('openrouter', 'openai/gpt-5-mini')))
        ->toBe(['openrouter' => 'openai/gpt-5-mini']);
});

test('providerChainFor appends the failover provider with its optional model', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setFailoverProvider(Lab::Anthropic);

    expect($aiSettings->providerChainFor(new ModelSelection('openrouter', 'openai/gpt-5-mini')))
        ->toBe(['openrouter' => 'openai/gpt-5-mini', 'anthropic' => null]);

    $aiSettings->setFailoverModel('claude-haiku-4-5');

    expect($aiSettings->providerChainFor(new ModelSelection('openrouter', 'openai/gpt-5-mini')))
        ->toBe(['openrouter' => 'openai/gpt-5-mini', 'anthropic' => 'claude-haiku-4-5']);
});

test('a failover provider equal to the selection provider collapses to one entry', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setFailoverProvider(Lab::OpenRouter);

    expect($aiSettings->providerChainFor(new ModelSelection('openrouter', 'anthropic/claude-sonnet-5')))
        ->toBe(['openrouter' => 'anthropic/claude-sonnet-5']);
});

test('a legacy install with failover keeps routing default provider then failover', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModel('gpt-5-mini');
    $aiSettings->setFailoverProvider(Lab::Anthropic);

    expect($aiSettings->providerChainFor($aiSettings->chatSelection()))
        ->toBe(['openai' => 'gpt-5-mini', 'anthropic' => null]);
});

test('a blank failover model clears back to the provider default', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setFailoverModel('claude-haiku-4-5');

    $aiSettings->setFailoverModel(null);

    expect($aiSettings->failoverModel())->toBeNull();
});

test('the decision agent inherits the chat selection until a model is saved', function (): void {
    config()->set('mediamanager.decision_agent.model', '');
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModelProvider('openrouter');
    $aiSettings->setModel('openai/gpt-5-mini');

    $decisionAgentSettings = resolve(DecisionAgentSettings::class);

    expect($decisionAgentSettings->selection())->toEqual(new ModelSelection('openrouter', 'openai/gpt-5-mini'));

    $decisionAgentSettings->setModel('claude-haiku-4-5');
    $decisionAgentSettings->setModelProvider('anthropic');

    expect($decisionAgentSettings->selection())->toEqual(new ModelSelection('anthropic', 'claude-haiku-4-5'))
        ->and($decisionAgentSettings->model())->toBe('claude-haiku-4-5');
});

test('a decision agent model saved before providers existed keeps the default provider', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setModel('gpt-5-mini');

    expect($decisionAgentSettings->selection())->toEqual(new ModelSelection('openai', 'gpt-5-mini'));
});
