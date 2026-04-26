<?php

declare(strict_types=1);

use App\Ai\Agents\CommandAgent;
use App\Ai\Tools\GetServiceStatusTool;
use App\Ai\Tools\SearchMediaTool;
use App\Listeners\Ai\RecordAgentUsage;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\User;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

function makeAgentPrompted(
    string $invocationId,
    object $agent,
    Usage $usage,
    Meta $meta,
    ?string $conversationId = null,
    ?object $conversationUser = null,
): AgentPrompted {
    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, $agent);

    $response = new AgentResponse($invocationId, 'response text', $usage, $meta);
    $response->conversationId = $conversationId;
    $response->conversationUser = $conversationUser;

    return new AgentPrompted($invocationId, $agentPrompt, $response);
}

test('writes one usage row with token counts and meta', function (): void {
    $user = User::factory()->create();

    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-abc',
        agent: new CommandAgent,
        usage: new Usage(promptTokens: 1234, completionTokens: 567, cacheWriteInputTokens: 50, cacheReadInputTokens: 100, reasoningTokens: 25),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        conversationId: 'conv-uuid',
        conversationUser: $user,
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $row = AiUsageRecord::where('invocation_id', 'inv-abc')->firstOrFail();

    expect($row->agent_class)->toBe(CommandAgent::class);
    expect($row->provider)->toBe('openai');
    expect($row->model)->toBe('gpt-5-mini');
    expect($row->prompt_tokens)->toBe(1234);
    expect($row->completion_tokens)->toBe(567);
    expect($row->cache_read_input_tokens)->toBe(100);
    expect($row->cache_write_input_tokens)->toBe(50);
    expect($row->reasoning_tokens)->toBe(25);
    expect($row->user_id)->toBe($user->id);
    expect($row->conversation_id)->toBe('conv-uuid');
    expect($row->status)->toBe('success');
});

test('tool_calls_count reflects rows already written for the same invocation', function (): void {
    AiToolInvocation::create([
        'invocation_id' => 'inv-multi',
        'tool_invocation_id' => 't1',
        'tool_class' => SearchMediaTool::class,
        'agent_class' => CommandAgent::class,
        'status' => 'success',
    ]);
    AiToolInvocation::create([
        'invocation_id' => 'inv-multi',
        'tool_invocation_id' => 't2',
        'tool_class' => GetServiceStatusTool::class,
        'agent_class' => CommandAgent::class,
        'status' => 'success',
    ]);

    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-multi',
        agent: new CommandAgent,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    expect(AiUsageRecord::where('invocation_id', 'inv-multi')->value('tool_calls_count'))->toBe(2);
});

test('handles missing conversation user gracefully', function (): void {
    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-anon',
        agent: new CommandAgent,
        usage: new Usage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $row = AiUsageRecord::where('invocation_id', 'inv-anon')->firstOrFail();
    expect($row->user_id)->toBeNull();
    expect($row->conversation_id)->toBeNull();
});
