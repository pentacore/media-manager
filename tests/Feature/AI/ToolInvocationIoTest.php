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

test('invalid UTF-8 in arguments or result still writes the row', function (): void {
    toolIoInvoke(resolve(GetServiceStatusTool::class), ['q' => "bad\xB1byte"], "res\xB1ult");

    $row = AiToolInvocation::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->arguments)->toBeArray()
        ->and(mb_check_encoding((string) $row->result, 'UTF-8'))->toBeTrue()
        ->and($row->result)->toStartWith('res')->toEndWith('ult');
});

test('an oversized multibyte result is cut to valid UTF-8 within the cap', function (): void {
    toolIoInvoke(resolve(GetServiceStatusTool::class), [], str_repeat('é', 20_000));

    $row = AiToolInvocation::query()->latest('id')->first();

    expect(mb_check_encoding((string) $row->result, 'UTF-8'))->toBeTrue()
        ->and($row->result)->toEndWith('…[truncated]')
        ->and(strlen((string) $row->result))->toBeLessThanOrEqual(AiToolInvocation::MAX_IO_BYTES + 20)
        ->and($row->io_truncated)->toBeTrue();
});

test('NUL characters in arguments and result are dropped so the row still writes', function (): void {
    toolIoInvoke(resolve(GetServiceStatusTool::class), ['q' => "Du\u{0000}ne", "ke\u{0000}y" => ['nested' => "x\u{0000}y"]], "res\u{0000}ult");

    $row = AiToolInvocation::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->arguments)->toBe(['q' => 'Dune', 'key' => ['nested' => 'xy']])
        ->and($row->result)->toBe('result');
});

test('a NUL character in an error envelope is dropped from the error code', function (): void {
    toolIoInvoke(resolve(GetServiceStatusTool::class), [], '{"error":"lookup\\u0000_failed"}');

    $row = AiToolInvocation::query()->latest('id')->first();

    expect($row->status)->toBe('failed')
        ->and($row->error_code)->toBe('lookup_failed');
});

test('a row whose I/O cannot be written is written without it instead of failing the run', function (): void {
    AiToolInvocation::creating(function (AiToolInvocation $aiToolInvocation): void {
        throw_if($aiToolInvocation->arguments !== null, RuntimeException::class, 'I/O rejected');
    });

    toolIoInvoke(resolve(GetServiceStatusTool::class), ['service' => 'sonarr'], '{"ok":true}');

    $row = AiToolInvocation::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->tool_name)->toBe('GetServiceStatusTool')
        ->and($row->arguments)->toBeNull()
        ->and($row->result)->toBeNull();
});
