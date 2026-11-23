<?php

declare(strict_types=1);

namespace App\Ai\Conversations;

/**
 * The choices or tool request card the chat turn in flight answers: the card's
 * tool call id, sent by the chat when the user clicks an option on it.
 * ChatController fills it before the agent runs; ConversationStore writes it
 * to the user message's `meta.answered_card`, so a reload marks the pick on
 * that card alone, not on every card of the reply. Scoped, so it never leaks
 * into the next Octane request or queued job.
 */
final class ChatAnsweredCard
{
    public ?string $id = null;
}
