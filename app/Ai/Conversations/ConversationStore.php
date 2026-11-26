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
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Override;
use Throwable;

/**
 * The SDK store, plus the reasoning level the turn ran at and, when the chat
 * model came from a tier list, which tier, plus the chat mode, in each assistant message's meta
 * (for the chat's "answered by" line). The reasoning key is
 * `reasoning_level`: `meta.reasoning` used to hold reasoning text.
 * A turn the user stopped ends with a cancelled stream (MediaAgent's
 * `Stop::when()`), which the SDK stores as a completed reply; it is flagged
 * `stopped` here (StoppedByUser::response()).
 * A user message that answered a choices or tool request card keeps that
 * card's id in `meta.answered_card` (ChatAnsweredCard).
 * A stopped or failed turn that produced nothing replays as a short
 * placeholder reply, so the next turn never sends two user messages in a row.
 * Stateless, so the SDK's singleton binding stays Octane-safe.
 */
class ConversationStore extends DatabaseConversationStore
{
    public const string STOPPED_PLACEHOLDER = '(The user stopped this reply.)';

    public const string FAILED_PLACEHOLDER = '(This reply failed.)';

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function metaFor(AgentResponse $response, ?Throwable $exception): array
    {
        $resolvedSelection = resolve(TaskModelResolver::class)->resolve(AiTask::Chat);
        $toolGroups = resolve(ChatToolRunContext::class)->toolGroups();

        return [
            ...parent::metaFor($response, $exception),
            ...(StoppedByUser::response($response) ? ['stopped' => true] : []),
            'invocation_id' => $response->invocationId,
            'mode' => resolve(AiSettings::class)->mode()->value,
            'reasoning_level' => $resolvedSelection->reasoning->value,
            ...($resolvedSelection->tier instanceof TierOutcome ? ['tier' => $resolvedSelection->tier->toArray()] : []),
            ...($toolGroups === null ? [] : ['tool_groups' => $toolGroups]),
        ];
    }

    /**
     * The SDK drops blank steps, so a turn stopped or failed before any
     * output rebuilds to no message at all and the next request would carry
     * two user messages in a row, which some providers reject. Such a turn
     * replays as a placeholder reply instead.
     *
     * @param  object{meta: string|null, status: string}  $record  a stored assistant row
     * @return array<int, Message>
     */
    #[Override]
    protected function assistantTurnFrom(object $record): array
    {
        $messages = parent::assistantTurnFrom($record);

        if ($messages !== []) {
            return $messages;
        }

        return match (true) {
            ($this->decoded($record->meta)['stopped'] ?? false) === true => [new AssistantMessage(self::STOPPED_PLACEHOLDER)],
            MessageStatus::tryFrom($record->status) === MessageStatus::Failed => [new AssistantMessage(self::FAILED_PLACEHOLDER)],
            default => [],
        };
    }

    /**
     * The SDK writes a user message with empty meta; one that answered a card names it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    protected function messageAttributes(string $messageId, string $conversationId, ?string $participantType, string|int|null $participantId, mixed $now, array $attributes): array
    {
        $answeredCard = resolve(ChatAnsweredCard::class)->id;

        if (($attributes['role'] ?? null) === 'user' && $answeredCard !== null) {
            $attributes['meta'] = json_encode(['answered_card' => $answeredCard], JSON_THROW_ON_ERROR);
        }

        return parent::messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, $attributes);
    }
}
