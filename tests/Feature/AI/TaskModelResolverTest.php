<?php

declare(strict_types=1);

use App\Ai\ChatTurnContext;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;

beforeEach(function (): void {
    config()->set('ai.default', 'openai');
    config()->set('mediamanager.ai.model', 'gpt-config-chat');
    config()->set('mediamanager.ai.title_model', 'gpt-config-title');
    config()->set('mediamanager.ai.advisor_reasoning_level', null);
    config()->set('mediamanager.ai.pricing.updater_model', '');
    config()->set('mediamanager.ai.sub_agent_model', '');
    config()->set('mediamanager.decision_agent.model', '');
    config()->set('mediamanager.decision_agent.reasoning_level', '');
});

function resolver(): TaskModelResolver
{
    $resolver = resolve(TaskModelResolver::class);
    $resolver->flush();

    return $resolver;
}

test('with no rows chat falls back to config and provider default reasoning', function (): void {
    $resolved = resolver()->resolve(AiTask::Chat);

    expect($resolved->provider)->toBe('openai')
        ->and($resolved->model)->toBe('gpt-config-chat')
        ->and($resolved->reasoning)->toBe(AiReasoningLevel::ProviderDefault);
});

test('the chat row wins over config, field by field', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('anthropic', 'claude-sonnet-5-5')->create();

    $resolved = resolver()->resolve(AiTask::Chat);

    expect($resolved->provider)->toBe('anthropic')
        ->and($resolved->model)->toBe('claude-sonnet-5-5')
        ->and($resolved->reasoning)->toBe(AiReasoningLevel::ProviderDefault);
});

test('a conversation override replaces the chat model and reasoning', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::Medium)->create();
    resolve(ChatTurnContext::class)->apply('anthropic', 'claude-opus-5-5', AiReasoningLevel::High);

    $resolved = resolver()->resolve(AiTask::Chat);

    expect([$resolved->provider, $resolved->model, $resolved->reasoning])
        ->toBe(['anthropic', 'claude-opus-5-5', AiReasoningLevel::High]);
});

test('a reasoning-only conversation override keeps the chat model', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::Medium)->create();
    resolve(ChatTurnContext::class)->apply(null, null, AiReasoningLevel::None);

    $resolved = resolver()->resolve(AiTask::Chat);

    expect([$resolved->model, $resolved->reasoning])->toBe(['gpt-5.6-luna', AiReasoningLevel::None]);
});

test('sub-agents take the conversation reasoning but not its model', function (AiTask $aiTask): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    resolve(ChatTurnContext::class)->apply('anthropic', 'claude-opus-5-5', AiReasoningLevel::High);

    $resolved = resolver()->resolve($aiTask);

    expect([$resolved->provider, $resolved->model, $resolved->reasoning])
        ->toBe(['openai', 'gpt-5.6-luna', AiReasoningLevel::High]);
})->with([AiTask::FileInspector, AiTask::StuckDownloadInvestigator]);

test('sub-agents use their own row model and reasoning without an override', function (): void {
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openai', 'gpt-5-nano')->reasoning(AiReasoningLevel::Low)->create();

    $resolved = resolver()->resolve(AiTask::FileInspector);

    expect([$resolved->model, $resolved->reasoning])->toBe(['gpt-5-nano', AiReasoningLevel::Low]);
});

test('a decision event override inherits each null field from the decision row', function (): void {
    AiTaskModel::factory()->task(AiTask::Decision)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::High)->create();
    AiTaskModel::factory()->event('sonarr:Download')->reasoning(AiReasoningLevel::None)->create();

    $overridden = resolver()->resolve(AiTask::Decision, 'sonarr:Download');
    $other = resolver()->resolve(AiTask::Decision, 'radarr:Grab');

    expect([$overridden->model, $overridden->reasoning])->toBe(['gpt-5.6-luna', AiReasoningLevel::None])
        ->and([$other->model, $other->reasoning])->toBe(['gpt-5.6-luna', AiReasoningLevel::High]);
});

test('the decision task follows the chat default when it has no model', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    resolve(ChatTurnContext::class)->apply('anthropic', 'claude-opus-5-5', null);

    expect(resolver()->resolve(AiTask::Decision)->model)->toBe('gpt-5.6-luna');
});

test('the decision config reasoning applies when no row sets one', function (): void {
    config()->set('mediamanager.decision_agent.reasoning_level', 'low');

    expect(resolver()->resolve(AiTask::Decision)->reasoning)->toBe(AiReasoningLevel::Low);
});

test('title falls back to its config model, not the chat model', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    expect(resolver()->resolve(AiTask::Title)->model)->toBe('gpt-config-title');
});

test('the price updater follows the chat default when unset', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    expect(resolver()->resolve(AiTask::PriceUpdater)->model)->toBe('gpt-5.6-luna');
});

test('a row with a model and no provider runs on the default provider', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->state(['model' => 'gpt-5-nano'])->create();

    expect(resolver()->resolve(AiTask::Title)->provider)->toBe('openai');
});

test('failover is off without a provider and carries an optional model', function (): void {
    expect(resolver()->failover())->toBeNull();

    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'openrouter'])->create();

    expect(resolver()->failover())->toBe(['provider' => 'openrouter', 'model' => null]);
});

test('saving a row flushes the memoised rows', function (): void {
    $resolver = resolver();
    expect($resolver->resolve(AiTask::Chat)->model)->toBe('gpt-config-chat');

    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    expect($resolver->resolve(AiTask::Chat)->model)->toBe('gpt-5.6-luna');
});
