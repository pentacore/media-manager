<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Streaming\Events\Error;

function streamErrorStep(Throwable $exception): StepFailed
{
    return new StepFailed('inv-stream', 1, new DecisionAgent, Ai::textProvider('openai'), 'gpt-5-mini', false, $exception, 812.5);
}

test('an error the provider sent inside the stream is logged with the run details', function (): void {
    Log::spy();

    event(streamErrorStep(new StreamErrorException(new Error(
        'evt-1', 'server_error', 'An error occurred. Please include the request ID req_abc123 in your message.', false, 1_700_000_000, ['param' => null],
    ))));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'AI provider stream error'
            && $context['provider'] === 'openai'
            && $context['model'] === 'gpt-5-mini'
            && $context['agent'] === DecisionAgent::class
            && $context['invocation_id'] === 'inv-stream'
            && $context['step'] === 2
            && $context['error_type'] === 'server_error'
            && str_contains((string) $context['error'], 'req_abc123')
            && $context['metadata'] === ['param' => null]);
});

test('a stream that ends without a response or an error event is logged too', function (): void {
    Log::spy();

    event(streamErrorStep(new StreamErrorException));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['error_type'] === null
            && $context['error'] === 'The provider ended the stream without completing the step.');
});

test('a thrown provider exception is left to the normal exception report', function (): void {
    Log::spy();

    event(streamErrorStep(new ProviderOverloadedException('overloaded')));

    Log::shouldNotHaveReceived('warning');
});
