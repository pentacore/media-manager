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
 * that lacks CodeExecution has it silently dropped. Neither fails, so an
 * ungated agent would quietly lose the payload saving or a promised tool on
 * a failover. If the SDK changes this again, these tests break.
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

test('sdk behaviour: CodeExecution on a provider without it is silently dropped', function (): void {
    (new CodeExecutionCharacterizationAgent)->prompt('hi', provider: 'mistral', model: 'mistral-small-latest');

    Http::assertSent(fn (Request $request): bool => ($request['tools'] ?? []) === []);
})->group('characterization');
