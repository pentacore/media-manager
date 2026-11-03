<?php

declare(strict_types=1);

namespace App\Ai\Conversations;

use App\Ai\Routing\ChatToolRunContext;
use App\Ai\StoppedByUser;
use App\Ai\TaskModelResolver;
use App\Ai\TierOutcome;
use App\Enums\AiTask;
use App\Settings\AiSettings;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Override;
use Throwable;

/**
 * The SDK store, plus the reasoning level the turn ran at and, when the chat
 * model came from a tier list, which tier, plus the chat mode, in each assistant message's meta
 * (for the chat's "answered by" line). The reasoning key is
 * `reasoning_level`: `meta.reasoning` used to hold reasoning text.
 * A turn the user stopped is stored as a completed reply flagged `stopped`,
 * never as a failure, whether the run ended cancelled or a sub-agent's
 * disconnect killed it.
 * Stateless, so the SDK's singleton binding stays Octane-safe.
 */
class ConversationStore extends DatabaseConversationStore
{
    #[Override]
    protected function statusFor(AgentResponse $response, ?Throwable $exception): MessageStatus
    {
        return $exception instanceof Throwable && StoppedByUser::exception($exception)
            ? MessageStatus::Completed
            : parent::statusFor($response, $exception);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function metaFor(AgentResponse $response, ?Throwable $exception): array
    {
        $stoppedByException = $exception instanceof Throwable && StoppedByUser::exception($exception);
        $resolvedSelection = resolve(TaskModelResolver::class)->resolve(AiTask::Chat);
        $toolGroups = resolve(ChatToolRunContext::class)->toolGroups();

        return [
            ...parent::metaFor($response, $stoppedByException ? null : $exception),
            ...($stoppedByException || StoppedByUser::response($response) ? ['stopped' => true] : []),
            'invocation_id' => $response->invocationId,
            'mode' => resolve(AiSettings::class)->mode()->value,
            'reasoning_level' => $resolvedSelection->reasoning->value,
            ...($resolvedSelection->tier instanceof TierOutcome ? ['tier' => $resolvedSelection->tier->toArray()] : []),
            ...($toolGroups === null ? [] : ['tool_groups' => $toolGroups]),
        ];
    }
}
