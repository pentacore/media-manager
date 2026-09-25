<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Ai\Tools\System\GetServiceStatusTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\ToolSearch;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Pins the laravel/ai behaviour ProviderCapabilities / ToolPayload exist to
 * avoid: sending ToolSearch or CodeExecution to a provider that lacks them
 * fails hard and does NOT fail over. If a future SDK release degrades
 * gracefully instead, these tests break and the gating can be revisited.
 */
class ToolSearchCharacterizationAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'characterization';
    }

    public function tools(): iterable
    {
        return [new ToolSearch([resolve(GetServiceStatusTool::class)])];
    }
}

class CodeExecutionCharacterizationAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'characterization';
    }

    public function tools(): iterable
    {
        return [new CodeExecution];
    }
}

function characterizationFailure(callable $prompt): Throwable
{
    try {
        $prompt();
    } catch (Throwable $throwable) {
        return $throwable;
    }

    throw new RuntimeException('Expected the SDK to reject the provider tool.');
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('sdk behaviour: ToolSearch on a provider without tool search throws a non-failoverable LogicException', function (): void {
    $throwable = characterizationFailure(fn () => (new ToolSearchCharacterizationAgent)->prompt('hi', provider: 'gemini', model: 'gemini-3.7-flash'));

    expect($throwable)->toBeInstanceOf(LogicException::class)
        ->and($throwable->getMessage())->toContain('does not support tool search')
        ->and($throwable)->not->toBeInstanceOf(FailoverableException::class);

    Http::assertNothingSent();
})->group('characterization');

test('sdk behaviour: CodeExecution on a chat-completions provider throws a non-failoverable error before any request', function (): void {
    $throwable = characterizationFailure(fn () => (new CodeExecutionCharacterizationAgent)->prompt('hi', provider: 'groq', model: 'llama-4-scout'));

    expect($throwable)->toBeInstanceOf(RuntimeException::class)
        ->and($throwable->getMessage())->toContain('does not support [CodeExecution] provider tools')
        ->and($throwable)->not->toBeInstanceOf(FailoverableException::class);

    Http::assertNothingSent();
})->group('characterization');
