<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\SearchMediaTool;
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
    string $responseText = 'response text',
): AgentPrompted {
    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, $agent);

    $response = new AgentResponse($invocationId, $responseText, $usage, $meta);
    $response->conversationId = $conversationId;
    $response->conversationUser = $conversationUser;

    return new AgentPrompted($invocationId, $agentPrompt, $response);
}

test('writes one usage row with token counts and meta', function (): void {
    $user = User::factory()->create();

    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-abc',
        agent: new MediaAgent,
        usage: new Usage(promptTokens: 1234, completionTokens: 567, cacheWriteInputTokens: 50, cacheReadInputTokens: 100, reasoningTokens: 25),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        conversationId: 'conv-uuid',
        conversationUser: $user,
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $row = AiUsageRecord::where('invocation_id', 'inv-abc')->firstOrFail();

    expect($row->agent_class)->toBe(MediaAgent::class);
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
        'agent_class' => MediaAgent::class,
        'status' => 'success',
    ]);
    AiToolInvocation::create([
        'invocation_id' => 'inv-multi',
        'tool_invocation_id' => 't2',
        'tool_class' => DeleteMediaTool::class,
        'agent_class' => MediaAgent::class,
        'status' => 'success',
    ]);

    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-multi',
        agent: new MediaAgent,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    expect(AiUsageRecord::where('invocation_id', 'inv-multi')->value('tool_calls_count'))->toBe(2);
});

test('handles missing conversation user gracefully', function (): void {
    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-anon',
        agent: new MediaAgent,
        usage: new Usage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $row = AiUsageRecord::where('invocation_id', 'inv-anon')->firstOrFail();
    expect($row->user_id)->toBeNull();
    expect($row->conversation_id)->toBeNull();
});

test('persists the agent response text on the row', function (): void {
    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-with-text',
        agent: new MediaAgent,
        usage: new Usage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        responseText: 'Found 3 series matching "severance".',
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    expect(AiUsageRecord::where('invocation_id', 'inv-with-text')->value('response_text'))
        ->toBe('Found 3 series matching "severance".');
});

test('truncates response text past 64 KB with an ellipsis suffix', function (): void {
    $longText = str_repeat('a', 70_000);
    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-long',
        agent: new MediaAgent,
        usage: new Usage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        responseText: $longText,
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $stored = AiUsageRecord::where('invocation_id', 'inv-long')->value('response_text');

    expect(strlen((string) $stored))->toBeLessThanOrEqual(65_536);
    expect($stored)->toEndWith('…');
});
