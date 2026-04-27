<?php

declare(strict_types=1);

use App\Ai\Storage\HealingConversationStore;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

function fakeInner(Collection $messages): ConversationStore
{
    return new class($messages) implements ConversationStore
    {
        public function __construct(private Collection $messages) {}

        public function latestConversationId(string|int $userId): ?string
        {
            return null;
        }

        public function storeConversation(string|int|null $userId, string $title): string
        {
            return 'fake';
        }

        public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
        {
            return 'fake';
        }

        public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
        {
            return 'fake';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return $this->messages;
        }
    };
}

test('healthy conversation passes through unchanged', function (): void {
    $messages = collect([
        new Message('user', 'hi'),
        new AssistantMessage('using a tool', collect([new ToolCall(id: 'call_1', name: 'X', arguments: [], resultId: null)])),
        new ToolResultMessage(collect([new ToolResult(id: 'call_1', name: 'X', arguments: [], result: 'done', resultId: null)])),
        new AssistantMessage('all done'),
    ]);

    $healing = new HealingConversationStore(fakeInner($messages));

    $result = $healing->getLatestConversationMessages('cid', 100);

    expect($result->count())->toBe(4);
});

test('orphan tool_call gets a synthetic ToolResultMessage injected after it', function (): void {
    $messages = collect([
        new Message('user', 'hi'),
        new AssistantMessage('using a tool', collect([new ToolCall(id: 'call_1', name: 'X', arguments: [], resultId: null)])),
        // missing ToolResultMessage for call_1 - orphan
        new Message('user', 'are you stuck?'),
    ]);

    $healing = new HealingConversationStore(fakeInner($messages));

    $result = $healing->getLatestConversationMessages('cid', 100)->values();

    expect($result->count())->toBe(4);
    expect($result[2])->toBeInstanceOf(ToolResultMessage::class);
    expect($result[2]->toolResults->first()->id)->toBe('call_1');
    expect($result[2]->toolResults->first()->result)->toContain('tool_output_lost');
});

test('multiple orphans are healed independently', function (): void {
    $messages = collect([
        new AssistantMessage('first call', collect([new ToolCall(id: 'a', name: 'X', arguments: [], resultId: null)])),
        new AssistantMessage('second call', collect([new ToolCall(id: 'b', name: 'Y', arguments: [], resultId: null)])),
    ]);

    $healing = new HealingConversationStore(fakeInner($messages));

    $result = $healing->getLatestConversationMessages('cid', 100)->values();

    expect($result->count())->toBe(4);
    expect($result[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($result[1]->toolResults->first()->id)->toBe('a');
    expect($result[3])->toBeInstanceOf(ToolResultMessage::class);
    expect($result[3]->toolResults->first()->id)->toBe('b');
});

test('partial tool_result coverage gets the missing IDs filled in', function (): void {
    $messages = collect([
        new AssistantMessage('parallel calls', collect([
            new ToolCall(id: 'a', name: 'X', arguments: [], resultId: null),
            new ToolCall(id: 'b', name: 'Y', arguments: [], resultId: null),
        ])),
        new ToolResultMessage(collect([
            new ToolResult(id: 'a', name: 'X', arguments: [], result: 'ok', resultId: null),
            // 'b' is missing
        ])),
    ]);

    $healing = new HealingConversationStore(fakeInner($messages));

    $result = $healing->getLatestConversationMessages('cid', 100)->values();

    expect($result->count())->toBe(2);
    $resultMessage = $result[1];
    expect($resultMessage->toolResults->count())->toBe(2);
    expect($resultMessage->toolResults->pluck('id')->all())->toEqual(['a', 'b']);
    expect($resultMessage->toolResults->firstWhere('id', 'b')->result)->toContain('tool_output_lost');
});
