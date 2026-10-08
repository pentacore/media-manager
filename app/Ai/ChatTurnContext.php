<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;

/**
 * The conversation's model/reasoning override for the chat turn in flight.
 * ChatController fills it before the agent runs; TaskModelResolver reads it.
 * Scoped, so it never leaks into the next Octane request or queued job.
 */
final class ChatTurnContext
{
    public ?string $provider = null;

    public ?string $model = null;

    public ?AiReasoningLevel $reasoning = null;

    public function apply(?string $provider, ?string $model, ?AiReasoningLevel $aiReasoningLevel): void
    {
        $hasPair = filled($provider) && filled($model);

        $this->provider = $hasPair ? $provider : null;
        $this->model = $hasPair ? $model : null;
        $this->reasoning = $aiReasoningLevel;
    }

    public function hasModel(): bool
    {
        return $this->model !== null;
    }

    public function clear(): void
    {
        $this->apply(null, null, null);
    }
}
