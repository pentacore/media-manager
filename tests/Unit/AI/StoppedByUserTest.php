<?php

declare(strict_types=1);

use App\Ai\Middleware\ClientDisconnectedException;
use App\Ai\StoppedByUser;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;

function streamEndedWith(string $reason): StreamedAgentResponse
{
    return new StreamedAgentResponse('inv-1', collect([new StreamEnd('end-1', $reason, new TextUsage, 0)]), new Meta);
}

test('a cancelled stream counts as a user stop', function (): void {
    expect(StoppedByUser::response(streamEndedWith('cancelled')))->toBeTrue();
});

test('a stream that ended normally is not a user stop', function (): void {
    expect(StoppedByUser::response(streamEndedWith('stop')))->toBeFalse()
        ->and(StoppedByUser::response(new AgentResponse('inv-2', 'text', new TextUsage, new Meta)))->toBeFalse();
});

test('a client disconnect counts as a user stop, also when wrapped', function (): void {
    expect(StoppedByUser::exception(new ClientDisconnectedException))->toBeTrue()
        ->and(StoppedByUser::exception(new RuntimeException('x', 0, new ClientDisconnectedException)))->toBeTrue()
        ->and(StoppedByUser::exception(new RuntimeException('x')))->toBeFalse();
});
