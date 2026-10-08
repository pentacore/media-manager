<?php

declare(strict_types=1);

use App\Ai\Conversations\HistoryForProvider;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * A user turn, an assistant tool call, its result and the final answer.
 *
 * @return list<Message>
 */
function historyForProviderToolCallHistory(?string $signature = 'sig-abc'): array
{
    $toolCall = new ToolCall('fc_123', 'get_media', ['id' => 1], 'call_123', thoughtSignature: $signature);

    return [
        new Message('user', 'Find it'),
        new AssistantMessage('', collect([$toolCall]), [], 'gemini'),
        new ToolResultMessage(collect([new ToolResult('fc_123', 'get_media', ['id' => 1], 'ok', 'call_123')])),
        new AssistantMessage('Found it.'),
    ];
}

test('gemini history drops stored thought signatures', function (): void {
    $adapted = HistoryForProvider::adapt(historyForProviderToolCallHistory(), 'gemini');

    expect($adapted[1]->toolCalls->first()->thoughtSignature)->toBeNull()
        ->and($adapted[1]->toolCalls->first()->id)->toBe('fc_123')
        ->and($adapted[1]->toolCalls->first()->resultId)->toBe('call_123')
        ->and($adapted[3]->content)->toBe('Found it.');
});

test('other providers get the history unchanged', function (string $provider): void {
    $history = historyForProviderToolCallHistory();

    expect(HistoryForProvider::adapt($history, $provider))->toBe($history);
})->with(['openai', 'anthropic', 'openrouter']);

test('mistral gets nine character ids that still pair calls with results', function (): void {
    $adapted = HistoryForProvider::adapt(historyForProviderToolCallHistory(null), 'mistral');

    $call = $adapted[1]->toolCalls->first();
    $result = $adapted[2]->toolResults->first();

    expect($call->id)->toMatch('/^[a-zA-Z0-9]{9}$/')
        ->and($call->resultId)->toMatch('/^[a-zA-Z0-9]{9}$/')
        ->and($result->id)->toBe($call->id)
        ->and($result->resultId)->toBe($call->resultId);
});
