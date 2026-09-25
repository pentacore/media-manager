<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Listeners\Ai\RecordAgentUsage;
use App\Listeners\Ai\RecordToolFailure;
use App\Listeners\Ai\RecordToolInvocation;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

test('writes one row per ToolInvoked event', function (): void {
    $event = new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-call-xyz',
        agent: new MediaAgent,
        tool: resolve(SearchMediaTool::class),
        arguments: ['q' => 'breaking bad'],
        result: ['hits' => []],
        time: 12.5,
    );

    (new RecordToolInvocation)->handle($event);

    $row = AiToolInvocation::where('invocation_id', 'inv-1')->firstOrFail();
    expect($row->tool_invocation_id)->toBe('tool-call-xyz');
    expect($row->tool_class)->toBe(SearchMediaTool::class);
    expect($row->agent_class)->toBe(MediaAgent::class);
    expect($row->status)->toBe('success');
});

test('end-to-end: tool events fire before AgentPrompted, count rolls up to the parent row', function (): void {
    $invocationId = 'inv-rollup';

    (new RecordToolInvocation)->handle(new ToolInvoked(
        invocationId: $invocationId,
        toolInvocationId: 'a',
        agent: new MediaAgent,
        tool: resolve(SearchMediaTool::class),
        arguments: [],
        result: null,
        time: 12.5,
    ));
    (new RecordToolInvocation)->handle(new ToolInvoked(
        invocationId: $invocationId,
        toolInvocationId: 'b',
        agent: new MediaAgent,
        tool: resolve(DeleteMediaTool::class),
        arguments: [],
        result: null,
        time: 12.5,
    ));

    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, new MediaAgent);

    $response = new AgentResponse($invocationId, 'ok', new TextUsage, new Meta(provider: 'openai', model: 'gpt-5-mini'));

    (new RecordAgentUsage)->handle(new AgentPrompted($invocationId, $agentPrompt, $response));

    expect(AiUsageRecord::where('invocation_id', $invocationId)->value('tool_calls_count'))->toBe(2);
    expect(AiToolInvocation::where('invocation_id', $invocationId)->count())->toBe(2);
});

test('records duration and derives failure from a BaseTool error envelope', function (): void {
    (new RecordToolInvocation)->handle(new ToolInvoked(
        invocationId: 'inv-err',
        toolInvocationId: 't1',
        agent: new MediaAgent,
        tool: resolve(SearchMediaTool::class),
        arguments: [],
        result: '{"error":"tool_failed","code":"request_exception","message":"x"}',
        time: 812.4,
    ));

    expect(AiToolInvocation::where('tool_invocation_id', 't1')->sole())
        ->status->toBe('failed')
        ->error_code->toBe('tool_failed')
        ->duration_ms->toBe(812);
});

test('a queued-false destructive result is not a failure', function (): void {
    (new RecordToolInvocation)->handle(new ToolInvoked(
        invocationId: 'inv-q',
        toolInvocationId: 't2',
        agent: new MediaAgent,
        tool: resolve(DeleteMediaTool::class),
        arguments: [],
        result: '{"queued":false,"reason":"no_action_type_config"}',
        time: 3.0,
    ));

    expect(AiToolInvocation::where('tool_invocation_id', 't2')->value('status'))->toBe('success');
});

test('ToolFailed writes a failed row with the exception code', function (): void {
    (new RecordToolFailure)->handle(new ToolFailed(
        invocationId: 'inv-f',
        toolInvocationId: 't3',
        agent: new MediaAgent,
        tool: resolve(SearchMediaTool::class),
        arguments: [],
        exception: new RuntimeException('boom'),
        time: 5.2,
    ));

    expect(AiToolInvocation::where('tool_invocation_id', 't3')->sole())
        ->status->toBe('failed')
        ->error_code->toBe('runtime_exception')
        ->duration_ms->toBe(5);
});
