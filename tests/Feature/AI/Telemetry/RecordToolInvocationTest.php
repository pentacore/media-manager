<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Listeners\Ai\RecordAgentUsage;
use App\Listeners\Ai\RecordToolInvocation;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

test('writes one row per ToolInvoked event', function (): void {
    $event = new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-call-xyz',
        agent: new MediaAgent,
        tool: resolve(SearchMediaTool::class),
        arguments: ['q' => 'breaking bad'],
        result: ['hits' => []],
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
    ));
    (new RecordToolInvocation)->handle(new ToolInvoked(
        invocationId: $invocationId,
        toolInvocationId: 'b',
        agent: new MediaAgent,
        tool: resolve(DeleteMediaTool::class),
        arguments: [],
        result: null,
    ));

    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, new MediaAgent);

    $response = new AgentResponse($invocationId, 'ok', new Usage, new Meta(provider: 'openai', model: 'gpt-5-mini'));

    (new RecordAgentUsage)->handle(new AgentPrompted($invocationId, $agentPrompt, $response));

    expect(AiUsageRecord::where('invocation_id', $invocationId)->value('tool_calls_count'))->toBe(2);
    expect(AiToolInvocation::where('invocation_id', $invocationId)->count())->toBe(2);
});
