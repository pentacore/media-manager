<?php

declare(strict_types=1);

namespace App\Ai\Conversations;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Stored history made safe for the provider about to receive it after a
 * model switch. The SDK already drops OpenAI reasoning ids at storage and
 * foreign replay blocks per turn; what's left is Gemini replaying stored
 * thought signatures (validated only within the current turn, so dropping
 * them from earlier turns is safe) and Mistral's 9-character id rule.
 */
final class HistoryForProvider
{
    /**
     * @param  iterable<int, mixed>  $messages
     * @return list<mixed>
     */
    public static function adapt(iterable $messages, string $provider): array
    {
        $messages = array_values([...$messages]);

        return match ($provider) {
            'gemini' => array_map(self::withoutSignatures(...), $messages),
            'mistral' => array_map(self::withShortIds(...), $messages),
            default => $messages,
        };
    }

    /**
     * Rebuild an assistant message's tool calls without their thought
     * signature. Replay blocks stay: Gemini replays its own paused turn from
     * them, and the SDK already drops another provider's blocks.
     */
    private static function withoutSignatures(mixed $message): mixed
    {
        if (! $message instanceof AssistantMessage || $message->toolCalls->isEmpty()) {
            return $message;
        }

        return new AssistantMessage(
            (string) $message->content,
            $message->toolCalls->map(static fn (ToolCall $toolCall): ToolCall => new ToolCall(
                id: $toolCall->id,
                name: $toolCall->name,
                arguments: $toolCall->arguments,
                resultId: $toolCall->resultId,
                reasoningId: $toolCall->reasoningId,
                reasoningSummary: $toolCall->reasoningSummary,
                reasoningEncryptedContent: $toolCall->reasoningEncryptedContent,
            )),
            $message->replayBlocks,
            $message->replayBlocksProvider,
        );
    }

    /**
     * Map every tool-call and tool-result id onto a stable 9-character
     * alphanumeric id, so calls still pair with their results.
     */
    private static function withShortIds(mixed $message): mixed
    {
        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            return new AssistantMessage(
                (string) $message->content,
                $message->toolCalls->map(static fn (ToolCall $toolCall): ToolCall => new ToolCall(
                    self::shortId($toolCall->id),
                    $toolCall->name,
                    $toolCall->arguments,
                    $toolCall->resultId === null ? null : self::shortId($toolCall->resultId),
                )),
                [],
                $message->replayBlocksProvider,
            );
        }

        if ($message instanceof ToolResultMessage) {
            return new ToolResultMessage($message->toolResults->map(static fn (ToolResult $toolResult): ToolResult => new ToolResult(
                self::shortId($toolResult->id),
                $toolResult->name,
                $toolResult->arguments,
                $toolResult->result,
                $toolResult->resultId === null ? null : self::shortId($toolResult->resultId),
                $toolResult->denied,
                $toolResult->failed,
            )));
        }

        return $message;
    }

    private static function shortId(string $id): string
    {
        return substr(hash('sha256', $id), 0, 9);
    }
}
