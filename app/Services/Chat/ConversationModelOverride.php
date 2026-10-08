<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Ai\ChatTurnContext;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use Illuminate\Support\Facades\DB;

/**
 * A conversation's saved model/reasoning override: loads it into the turn
 * context before the agent runs and stores the first turn's choice on a new
 * conversation.
 */
final readonly class ConversationModelOverride
{
    public function __construct(
        private ChatTurnContext $chatTurnContext,
        private TaskModelResolver $taskModelResolver,
    ) {}

    /**
     * @return array{provider: string|null, model: string|null, reasoning: string|null}
     */
    public function forConversation(string $conversationId): array
    {
        $row = DB::table('agent_conversations')->where('id', $conversationId)->first(['model_provider', 'model', 'reasoning']);

        return [
            'provider' => $row?->model_provider,
            'model' => $row?->model,
            'reasoning' => $row?->reasoning,
        ];
    }

    /**
     * @param  array{provider?: string|null, model?: string|null, reasoning?: string|null}  $requested
     */
    public function applyToTurn(?string $conversationId, array $requested): void
    {
        $override = $conversationId === null ? $requested : $this->forConversation($conversationId);

        $this->chatTurnContext->apply(
            $override['provider'] ?? null,
            $override['model'] ?? null,
            AiReasoningLevel::tryFrom((string) ($override['reasoning'] ?? '')),
        );
    }

    public function persist(string $conversationId): void
    {
        DB::table('agent_conversations')->where('id', $conversationId)->update([
            'model_provider' => $this->chatTurnContext->provider,
            'model' => $this->chatTurnContext->model,
            'reasoning' => $this->chatTurnContext->reasoning?->value,
        ]);
    }

    /**
     * The admin chat default, ignoring any conversation override.
     *
     * @return array{provider: string, model: string, reasoning: string, reasoning_label: string, tier: array{position: int, count: int, reason: string|null}|null}
     */
    public function chatDefaults(): array
    {
        $saved = [$this->chatTurnContext->provider, $this->chatTurnContext->model, $this->chatTurnContext->reasoning];
        $this->chatTurnContext->clear();

        $resolvedSelection = $this->taskModelResolver->resolve(AiTask::Chat);

        $this->chatTurnContext->apply(...$saved);

        return [
            'provider' => $resolvedSelection->provider,
            'model' => $resolvedSelection->model,
            'reasoning' => $resolvedSelection->reasoning->value,
            'reasoning_label' => $resolvedSelection->reasoning->label(),
            'tier' => $resolvedSelection->tier?->toArray(),
        ];
    }
}
