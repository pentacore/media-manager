<?php

declare(strict_types=1);

use App\Ai\Storage\HealingConversationStore;
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
use Laravel\Ai\Storage\DatabaseConversationStore;

function fakeInner(Collection $messages): ConversationStore
{
    return new readonly class($messages) implements ConversationStore
    {
        public function __construct(private Collection $messages) {}

        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            return null;
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
        {
            return 'fake';
        }

        public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
        {
            return 'fake';
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): string
        {
            return 'fake';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return $this->messages;
        }

        public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
        {
            // No-op for the fake store.
        }
    };
}

test('healthy conversation passes through unchanged', function (): void {
    $messages = collect([
        new Message('user', 'hi'),
        new AssistantMessage('using a tool', collect([new ToolCall(id: 'call_1', name: 'X', arguments: [])])),
        new ToolResultMessage(collect([new ToolResult(id: 'call_1', name: 'X', arguments: [], result: 'done')])),
        new AssistantMessage('all done'),
    ]);

    $healing = new HealingConversationStore(fakeInner($messages));

    $result = $healing->getLatestConversationMessages('cid', 100);

    expect($result->count())->toBe(4);
});

test('orphan tool_call gets a synthetic ToolResultMessage injected after it', function (): void {
    $messages = collect([
        new Message('user', 'hi'),
        new AssistantMessage('using a tool', collect([new ToolCall(id: 'call_1', name: 'X', arguments: [])])),
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
        new AssistantMessage('first call', collect([new ToolCall(id: 'a', name: 'X', arguments: [])])),
        new AssistantMessage('second call', collect([new ToolCall(id: 'b', name: 'Y', arguments: [])])),
    ]);

    $healing = new HealingConversationStore(fakeInner($messages));

    $result = $healing->getLatestConversationMessages('cid', 100)->values();

    expect($result->count())->toBe(4);
    expect($result[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($result[1]->toolResults->first()->id)->toBe('a');
    expect($result[3])->toBeInstanceOf(ToolResultMessage::class);
    expect($result[3]->toolResults->first()->id)->toBe('b');
});

test('reasoningId and reasoningSummary are restored from raw DB rows', function (): void {
    $conversationId = '01900000-0000-7000-0000-000000000001';

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => null,
        'participant_id' => null,
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => '01900000-0000-7000-0000-000000000002',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => null,
        'agent' => 'TestAgent',
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            [
                'id' => 'fc_abc',
                'name' => 'SearchMediaTool',
                'arguments' => ['q' => 'breaking bad'],
                'result_id' => 'call_xyz',
                'reasoning_id' => 'rs_def',
                'reasoning_summary' => [['type' => 'summary_text', 'text' => 'reasoning text']],
            ],
        ]),
        'tool_results' => json_encode([
            ['id' => 'fc_abc', 'name' => 'SearchMediaTool', 'arguments' => [], 'result' => 'ok', 'result_id' => 'call_xyz'],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $healing = new HealingConversationStore(new DatabaseConversationStore);

    $messages = $healing->getLatestConversationMessages($conversationId, 100)->values();

    /** @var AssistantMessage $assistant */
    $assistant = $messages->first(fn (Message $message): bool => $message instanceof AssistantMessage);
    /** @var ToolCall $toolCall */
    $toolCall = $assistant->toolCalls->first();

    expect($toolCall->id)->toBe('fc_abc');
    expect($toolCall->reasoningId)->toBe('rs_def');
    expect($toolCall->reasoningSummary)->toBe([['type' => 'summary_text', 'text' => 'reasoning text']]);
});

test('partial tool_result coverage gets the missing IDs filled in', function (): void {
    $messages = collect([
        new AssistantMessage('parallel calls', collect([
            new ToolCall(id: 'a', name: 'X', arguments: []),
            new ToolCall(id: 'b', name: 'Y', arguments: []),
        ])),
        new ToolResultMessage(collect([
            new ToolResult(id: 'a', name: 'X', arguments: [], result: 'ok'),
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
