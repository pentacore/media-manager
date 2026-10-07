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

test('the price updater falls back to its config model before the chat model', function (): void {
    config()->set('mediamanager.ai.pricing.updater_model', 'gpt-config-updater');
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    expect(resolver()->resolve(AiTask::PriceUpdater)->model)->toBe('gpt-config-updater');
});

test('a price updater row wins over its config model and clears back to it', function (): void {
    config()->set('mediamanager.ai.pricing.updater_model', 'gpt-config-updater');
    $priceUpdater = AiTaskModel::factory()->task(AiTask::PriceUpdater)->selecting('openai', 'gpt-saved-updater')->create();

    expect(resolver()->resolve(AiTask::PriceUpdater)->model)->toBe('gpt-saved-updater');

    $priceUpdater->delete();

    expect(resolver()->resolve(AiTask::PriceUpdater)->model)->toBe('gpt-config-updater');
});

test('a row with a model and no provider runs on the default provider, not the chat provider', function (AiTask $aiTask): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'x-ai/grok-4')->create();
    AiTaskModel::factory()->task($aiTask)->state(['model' => 'gpt-5-nano'])->create();

    $modelSelection = resolver()->resolve($aiTask)->modelSelection();

    expect([$modelSelection->provider, $modelSelection->model])->toBe(['openai', 'gpt-5-nano']);
})->with([AiTask::Title, AiTask::Decision, AiTask::FileInspector, AiTask::StuckDownloadInvestigator, AiTask::PriceUpdater]);

test('a chat row with a model and no provider runs on the default provider', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->state(['model' => 'gpt-5-mini'])->create();

    $modelSelection = resolver()->resolve(AiTask::Chat)->modelSelection();

    expect($modelSelection->toArray())->toBe(['provider' => 'openai', 'model' => 'gpt-5-mini']);
});

test('tasks that follow the chat model inherit the whole chat pair when unset', function (AiTask $aiTask): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'x-ai/grok-4')->create();

    $modelSelection = resolver()->resolve($aiTask)->modelSelection();

    expect([$modelSelection->provider, $modelSelection->model])->toBe(['openrouter', 'x-ai/grok-4']);
})->with([AiTask::Decision, AiTask::FileInspector, AiTask::StuckDownloadInvestigator, AiTask::PriceUpdater]);

test('a task row with its own provider and model runs on that pair', function (AiTask $aiTask, string $provider, string $model): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    AiTaskModel::factory()->task($aiTask)->selecting($provider, $model)->create();

    $modelSelection = resolver()->resolve($aiTask)->modelSelection();

    expect([$modelSelection->provider, $modelSelection->model])->toBe([$provider, $model]);
})->with([
    [AiTask::Decision, 'anthropic', 'claude-haiku-4-5'],
    [AiTask::FileInspector, 'openrouter', 'anthropic/claude-haiku-4.5'],
    [AiTask::StuckDownloadInvestigator, 'openrouter', 'anthropic/claude-haiku-4.5'],
    [AiTask::PriceUpdater, 'anthropic', 'claude-haiku-4-5'],
]);

test('the auto title model resolves to the title provider cheapest model', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openrouter', 'auto')->create();

    $modelSelection = resolver()->resolve(AiTask::Title)->modelSelection();

    expect([$modelSelection->provider, $modelSelection->model])->toBe(['openrouter', 'anthropic/claude-haiku-4.5']);
});

test('failover is off without a provider and carries an optional model', function (): void {
    expect(resolver()->failover())->toBeNull();

    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'openrouter'])->create();

    expect(resolver()->failover())->toBe(['provider' => 'openrouter', 'model' => null]);
});

test('a failover row carries its model, and a blank model means the provider default', function (): void {
    $failover = AiTaskModel::factory()->task(AiTask::Failover)->selecting('anthropic', 'claude-haiku-4-5')->create();

    expect(resolver()->failover())->toBe(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);

    $failover->update(['model' => '']);

    expect(resolver()->failover())->toBe(['provider' => 'anthropic', 'model' => null]);
});

test('saving a row flushes the memoised rows', function (): void {
    $resolver = resolver();
    expect($resolver->resolve(AiTask::Chat)->model)->toBe('gpt-config-chat');

    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    expect($resolver->resolve(AiTask::Chat)->model)->toBe('gpt-5.6-luna');
});
