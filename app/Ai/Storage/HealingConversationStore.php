<?php

declare(strict_types=1);

namespace App\Ai\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Override;

class HealingConversationStore implements ConversationStore
{
    public function __construct(private readonly ConversationStore $conversationStore) {}

    #[Override]
    public function latestConversationId(string|int $userId): ?string
    {
        return $this->conversationStore->latestConversationId($userId);
    }

    #[Override]
    public function storeConversation(string|int|null $userId, string $title): string
    {
        return $this->conversationStore->storeConversation($userId, $title);
    }

    #[Override]
    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        return $this->conversationStore->storeUserMessage($conversationId, $userId, $prompt);
    }

    #[Override]
    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        return $this->conversationStore->storeAssistantMessage($conversationId, $userId, $prompt, $response);
    }

    /**
     * @return Collection<int, Message>
     */
    #[Override]
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $messages = $this->conversationStore->getLatestConversationMessages($conversationId, $limit);
        $messages = $this->restoreReasoning($conversationId, $messages);

        return $this->heal($messages);
    }

    /**
     * Restore reasoning metadata onto rehydrated ToolCall objects. The upstream
     * DatabaseConversationStore stores reasoning_id / reasoning_summary via
     * ToolCall::toArray but reconstructs ToolCall instances without them. OpenAI's
     * Responses API then 400s on the next request because the function_call's
     * paired reasoning block is missing from input.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    private function restoreReasoning(string $conversationId, Collection $messages): Collection
    {
        $reasoningByToolCallId = $this->loadReasoningByToolCallId($conversationId);

        if ($reasoningByToolCallId === []) {
            return $messages;
        }

        return $messages->map(function (Message $message) use ($reasoningByToolCallId): Message {
            if (! $message instanceof AssistantMessage || $message->toolCalls->isEmpty()) {
                return $message;
            }

            $message->toolCalls = $message->toolCalls->map(function (ToolCall $toolCall) use ($reasoningByToolCallId): ToolCall {
                $reasoning = $reasoningByToolCallId[$toolCall->id] ?? null;

                if ($reasoning === null) {
                    return $toolCall;
                }

                return new ToolCall(
                    id: $toolCall->id,
                    name: $toolCall->name,
                    arguments: $toolCall->arguments,
                    resultId: $toolCall->resultId,
                    reasoningId: $reasoning['reasoning_id'] ?? null,
                    reasoningSummary: $reasoning['reasoning_summary'] ?? null,
                );
            });

            return $message;
        });
    }

    /**
     * @return array<string, array{reasoning_id: ?string, reasoning_summary: ?array<int, mixed>}>
     */
    private function loadReasoningByToolCallId(string $conversationId): array
    {
        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNotIn('tool_calls', ['', '[]', 'null'])
            ->pluck('tool_calls');

        $map = [];

        foreach ($rows as $row) {
            $toolCalls = json_decode((string) $row, true) ?? [];

            foreach ($toolCalls as $toolCall) {
                if (! is_array($toolCall)) {
                    continue;
                }
                if (! isset($toolCall['id'])) {
                    continue;
                }
                if (! isset($toolCall['reasoning_id'])) {
                    continue;
                }

                $map[$toolCall['id']] = [
                    'reasoning_id' => $toolCall['reasoning_id'],
                    'reasoning_summary' => $toolCall['reasoning_summary'] ?? null,
                ];
            }
        }

        return $map;
    }

    /**
     * Walk messages in order. For each AssistantMessage with tool_calls,
     * ensure the very next message is a ToolResultMessage covering every
     * tool_call_id. If it isn't, inject (or extend) a stub.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    private function heal(Collection $messages): Collection
    {
        $list = $messages->values()->all();
        $count = count($list);
        $healed = [];

        for ($i = 0; $i < $count; $i++) {
            $message = $list[$i];
            $healed[] = $message;

            if (! $message instanceof AssistantMessage) {
                continue;
            }

            $toolCalls = $message->toolCalls;

            if ($toolCalls->isEmpty()) {
                continue;
            }

            /** @var array<int, string> $expectedIds */
            $expectedIds = $toolCalls->pluck('id')->all();
            $next = $list[$i + 1] ?? null;

            if ($next instanceof ToolResultMessage) {
                $providedIds = $next->toolResults->pluck('id')->all();
                $missingIds = array_values(array_diff($expectedIds, $providedIds));

                if ($missingIds === []) {
                    continue;
                }

                $merged = $next->toolResults->concat(collect($missingIds)->map(
                    fn (string $id): ToolResult => $this->stubResult($id, $toolCalls->firstWhere('id', $id)?->name ?? 'unknown')
                ));

                $healed[array_key_last($healed)] = $message;
                $healed[] = new ToolResultMessage($merged);
                $i++; // we replaced $list[$i+1]

                continue;
            }

            $stubResults = collect($expectedIds)->map(
                fn (string $id): ToolResult => $this->stubResult($id, $toolCalls->firstWhere('id', $id)?->name ?? 'unknown')
            );

            $healed[] = new ToolResultMessage($stubResults);
        }

        return collect($healed);
    }

    private function stubResult(string $id, string $name): ToolResult
    {
        return new ToolResult(
            id: $id,
            name: $name,
            arguments: [],
            result: (string) json_encode([
                'error' => 'tool_output_lost',
                'code' => 'orphan_recovered',
                'message' => 'A previous tool call was lost. Do not retry the exact same call; tell the user what you were trying to do.',
            ]),
        );
    }
}
