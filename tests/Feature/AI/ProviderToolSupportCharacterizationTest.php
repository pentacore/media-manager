<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Ai\Tools\System\GetServiceStatusTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\ToolSearch;

/**
 * Pins the laravel/ai behaviour ProviderCapabilities / ToolPayload gate on:
 * a provider that lacks ToolSearch gets every deferred tool inlined, and one
 * that lacks CodeExecution has it silently dropped. Neither fails. The
 * inlining is why ToolPayload gates ToolSearch on the primary provider only
 * (a fallback still gets every tool), and the dropping is why CodeExecution
 * stays gated on the whole chain. If the SDK changes this again, these tests
 * break.
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

beforeEach(function (): void {
    config()->set('ai.providers.mistral.key', 'mistral-test-key');

    Http::preventStrayRequests();
    Http::fake([
        'api.mistral.ai/v1/chat/completions' => Http::response([
            'id' => 'chat-1',
            'model' => 'mistral-small-latest',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
        ]),
    ]);
});

test('sdk behaviour: ToolSearch on a provider without tool search inlines the deferred tools', function (): void {
    (new ToolSearchCharacterizationAgent)->prompt('hi', provider: 'mistral', model: 'mistral-small-latest');

    Http::assertSent(fn (Request $request): bool => count($request['tools'] ?? []) === 1
        && $request['tools'][0]['type'] === 'function'
        && $request['tools'][0]['function']['description'] === resolve(GetServiceStatusTool::class)->description());
})->group('characterization');

test('sdk behaviour: a failover to a provider without tool search inlines the deferred tools there', function (): void {
    config()->set('ai.providers.anthropic.key', 'anthropic-test-key');
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429)]);

    $response = (new ToolSearchCharacterizationAgent)->prompt('hi', provider: ['anthropic' => 'claude-sonnet-4-5', 'mistral' => 'mistral-small-latest']);

    expect($response->text)->toBe('ok');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.anthropic.com')
        && collect($request['tools'] ?? [])->contains(fn (array $tool): bool => str_starts_with((string) ($tool['type'] ?? ''), 'tool_search')));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.mistral.ai')
        && count($request['tools'] ?? []) === 1
        && $request['tools'][0]['type'] === 'function'
        && $request['tools'][0]['function']['description'] === resolve(GetServiceStatusTool::class)->description());
})->group('characterization');

test('sdk behaviour: CodeExecution on a provider without it is silently dropped', function (): void {
    (new CodeExecutionCharacterizationAgent)->prompt('hi', provider: 'mistral', model: 'mistral-small-latest');

    Http::assertSent(fn (Request $request): bool => ($request['tools'] ?? []) === []);
})->group('characterization');
