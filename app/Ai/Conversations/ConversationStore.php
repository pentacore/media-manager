<?php

declare(strict_types=1);

namespace App\Ai\Conversations;

use App\Ai\TaskModelResolver;
use App\Enums\AiTask;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Override;
use Throwable;

/**
 * The SDK store, plus the reasoning level the turn ran at in each assistant
 * message's meta (for the chat's "answered by" line). The key is
 * `reasoning_level`: `meta.reasoning` used to hold reasoning text.
 * Stateless, so the SDK's singleton binding stays Octane-safe.
 */
class ConversationStore extends DatabaseConversationStore
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function metaFor(AgentResponse $response, ?Throwable $exception): array
    {
        return [
            ...parent::metaFor($response, $exception),
            'reasoning_level' => resolve(TaskModelResolver::class)->resolve(AiTask::Chat)->reasoning->value,
        ];
    }
}
