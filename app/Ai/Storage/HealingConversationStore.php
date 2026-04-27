<?php

declare(strict_types=1);

namespace App\Ai\Storage;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
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

        return $this->heal($messages);
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
