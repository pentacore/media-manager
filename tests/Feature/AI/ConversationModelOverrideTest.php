<?php

declare(strict_types=1);

use App\Ai\ChatTurnContext;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Services\Chat\ConversationModelOverride;

test('chat defaults leaves the turn override in place when resolving fails', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5-nano')->create();
    AiTaskModel::retrieved(static function (): void {
        throw new RuntimeException('resolver down');
    });
    $chatTurnContext = resolve(ChatTurnContext::class);
    $chatTurnContext->apply('anthropic', 'claude-sonnet-5-5', AiReasoningLevel::High);

    try {
        resolve(ConversationModelOverride::class)->chatDefaults();
    } catch (RuntimeException) {
        // expected: the override must survive it
    } finally {
        AiTaskModel::flushEventListeners();
    }

    expect([$chatTurnContext->provider, $chatTurnContext->model, $chatTurnContext->reasoning])
        ->toBe(['anthropic', 'claude-sonnet-5-5', AiReasoningLevel::High]);
});
