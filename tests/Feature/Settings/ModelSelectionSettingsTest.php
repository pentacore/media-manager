<?php

declare(strict_types=1);

use App\Ai\ChatTurnContext;
use App\Ai\ModelSelection;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.default', 'openai');
    config()->set('mediamanager.ai.model', 'gpt-5-mini');
});

test('the chat selection without a chat row is the config model on the default provider', function (): void {
    expect(resolve(AiSettings::class)->chatSelection())->toEqual(new ModelSelection('openai', 'gpt-5-mini'));
});

test('the chat selection reads the AI Models chat row', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'anthropic/claude-sonnet-5')->create();

    expect(resolve(AiSettings::class)->chatSelection())->toEqual(new ModelSelection('openrouter', 'anthropic/claude-sonnet-5'));
});

test('the chat selection honours the conversation override for the turn in flight', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'anthropic/claude-sonnet-5')->create();
    resolve(ChatTurnContext::class)->apply('anthropic', 'claude-opus-5-5', null);

    expect(resolve(AiSettings::class)->chatSelection())->toEqual(new ModelSelection('anthropic', 'claude-opus-5-5'));
});

test('providerChainFor without failover is a single explicit entry', function (): void {
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->providerChainFor(new ModelSelection('openrouter', 'openai/gpt-5-mini')))
        ->toBe(['openrouter' => 'openai/gpt-5-mini']);
});

test('providerChainFor appends the failover provider with its optional model', function (): void {
    $failover = AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'anthropic'])->create();
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->providerChainFor(new ModelSelection('openrouter', 'openai/gpt-5-mini')))
        ->toBe(['openrouter' => 'openai/gpt-5-mini', 'anthropic' => null]);

    $failover->update(['model' => 'claude-haiku-4-5']);

    expect($aiSettings->providerChainFor(new ModelSelection('openrouter', 'openai/gpt-5-mini')))
        ->toBe(['openrouter' => 'openai/gpt-5-mini', 'anthropic' => 'claude-haiku-4-5']);
});

test('a failover provider equal to the selection provider collapses to one entry', function (): void {
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'openrouter'])->create();

    expect(resolve(AiSettings::class)->providerChainFor(new ModelSelection('openrouter', 'anthropic/claude-sonnet-5')))
        ->toBe(['openrouter' => 'anthropic/claude-sonnet-5']);
});

test('an install without a chat row routes the default provider then failover', function (): void {
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'anthropic'])->create();
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->providerChainFor($aiSettings->chatSelection()))
        ->toBe(['openai' => 'gpt-5-mini', 'anthropic' => null]);
});
