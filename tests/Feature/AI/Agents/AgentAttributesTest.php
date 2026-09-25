<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\SubtitleAdvisorAgent;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Responses\Data\ToolCall;

test('tool-using agents repair unknown tool calls', function (string $agent): void {
    expect((new ReflectionClass($agent))->getAttributes(RepairToolCalls::class))->not->toBeEmpty();
})->with([MediaAgent::class, DecisionAgent::class, SubtitleAdvisorAgent::class, PriceFetcherAgent::class]);

test('large-prompt agents cache instructions and tool definitions', function (string $agent): void {
    $reflection = new ReflectionClass($agent);

    expect($reflection->getAttributes(CacheInstructions::class))->not->toBeEmpty()
        ->and($reflection->getAttributes(CacheToolDefinitions::class))->not->toBeEmpty();
})->with([MediaAgent::class, DecisionAgent::class, PriceFetcherAgent::class]);

test('a call to a tool missing from this turn is repaired instead of crashing the run', function (): void {
    MediaAgent::fake([new ToolCall(id: 'c1', name: 'DeleteMediaTool', arguments: []), 'Recovered without that tool.']);

    $response = (new MediaAgent)->withTools(fn (array $declared): array => [])->prompt('delete it');

    expect($response->text)->toBe('Recovered without that tool.');
});
