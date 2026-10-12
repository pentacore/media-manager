<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Models\AiToolInvocation;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\AgentTool;

/**
 * @param  array<string, mixed>  $arguments
 */
function toolIoInvoke(Tool $tool, array $arguments, string $result): void
{
    event(new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'call-1',
        agent: new MediaAgent,
        tool: $tool,
        arguments: $arguments,
        result: $result,
        time: 12.0,
    ));
}

test('a tool call stores its name, arguments and result', function (): void {
    toolIoInvoke(resolve(GetServiceStatusTool::class), ['service' => 'sonarr'], '{"ok":true}');

    $row = AiToolInvocation::query()->latest('id')->first();

    expect($row->tool_name)->toBe('GetServiceStatusTool')
        ->and($row->arguments)->toBe(['service' => 'sonarr'])
        ->and($row->result)->toBe('{"ok":true}')
        ->and($row->io_truncated)->toBeFalse();
});

test('a sub-agent tool call records the sub-agent name', function (): void {
    toolIoInvoke(new AgentTool(resolve(MediaFileInspectorAgent::class)), ['task' => 'Inspect Dune'], '{"service":"radarr"}');

    expect(AiToolInvocation::query()->latest('id')->value('tool_name'))->toBe('InspectMediaFile');
});

test('oversized arguments and results are truncated and flagged', function (): void {
    toolIoInvoke(resolve(GetServiceStatusTool::class), ['q' => str_repeat('a', 40_000)], str_repeat('b', 40_000));

    $row = AiToolInvocation::query()->latest('id')->first();

    expect(strlen((string) $row->result))->toBeLessThanOrEqual(AiToolInvocation::MAX_IO_BYTES + 20)
        ->and($row->result)->toEndWith('…[truncated]')
        ->and($row->arguments)->toBe(['_truncated' => true])
        ->and($row->io_truncated)->toBeTrue();
});
