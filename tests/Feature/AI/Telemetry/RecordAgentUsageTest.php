<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\AiRunAttribution;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Listeners\Ai\RecordAgentUsage;
use App\Models\AiModelPrice;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\AiUsage\BatchPricingContext;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\Prompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

function makeAgentPrompted(
    string $invocationId,
    object $agent,
    TextUsage $usage,
    Meta $meta,
    ?string $conversationId = null,
    ?object $conversationUser = null,
    string $responseText = 'response text',
    string $promptText = 'prompt text',
    array $attachments = [],
): AgentPrompted {
    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, $agent);
    new ReflectionProperty(Prompt::class, 'prompt')->setValue($agentPrompt, $promptText);
    new ReflectionProperty(AgentPrompt::class, 'attachments')->setValue($agentPrompt, collect($attachments));

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
        usage: new TextUsage(inputTokens: 1384, outputTokens: 592, cacheReadInputTokens: 100, cacheWriteInputTokens: 50, reasoningTokens: 25),
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
        usage: new TextUsage(inputTokens: 10, outputTokens: 5),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    expect(AiUsageRecord::where('invocation_id', 'inv-multi')->value('tool_calls_count'))->toBe(2);
});

test('handles missing conversation user gracefully', function (): void {
    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-anon',
        agent: new MediaAgent,
        usage: new TextUsage,
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
        usage: new TextUsage,
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
        usage: new TextUsage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        responseText: $longText,
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $stored = AiUsageRecord::where('invocation_id', 'inv-long')->value('response_text');

    expect(strlen((string) $stored))->toBeLessThanOrEqual(65_536);
    expect($stored)->toEndWith('…');
});

test('persists the prompt the agent was sent on the row', function (): void {
    (new RecordAgentUsage)->handle(makeAgentPrompted(
        invocationId: 'inv-with-prompt',
        agent: new MediaAgent,
        usage: new TextUsage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        promptText: 'Find severance in Sonarr.',
    ));

    expect(AiUsageRecord::where('invocation_id', 'inv-with-prompt')->value('prompt_text'))
        ->toBe('Find severance in Sonarr.');
});

test('appends attachment names to the stored prompt', function (): void {
    (new RecordAgentUsage)->handle(makeAgentPrompted(
        invocationId: 'inv-with-attachments',
        agent: new MediaAgent,
        usage: new TextUsage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        promptText: 'What is in these?',
        attachments: [
            Document::fromString('hello', 'text/plain')->as('notes.txt'),
            Document::fromString('unnamed', 'text/plain'),
        ],
    ));

    expect(AiUsageRecord::where('invocation_id', 'inv-with-attachments')->value('prompt_text'))
        ->toBe("What is in these?\n\nAttachments: notes.txt, Base64Document");
});

test('truncates prompt text past 64 KB with an ellipsis suffix', function (): void {
    (new RecordAgentUsage)->handle(makeAgentPrompted(
        invocationId: 'inv-long-prompt',
        agent: new MediaAgent,
        usage: new TextUsage,
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        promptText: str_repeat('é', 40_000),
    ));

    $stored = (string) AiUsageRecord::where('invocation_id', 'inv-long-prompt')->value('prompt_text');

    expect(strlen($stored))->toBeLessThanOrEqual(65_536)
        ->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue()
        ->and($stored)->toEndWith('…');
});

test('batch usage is priced with batch rates when available', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 1.00,
        'output_per_mtok' => 4.00,
        'batch_input_per_mtok' => 0.50,
        'batch_output_per_mtok' => 2.00,
    ]);

    resolve(BatchPricingContext::class)->enabled = true;

    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-batch',
        agent: new MediaAgent,
        usage: new TextUsage(inputTokens: 100, outputTokens: 50),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $record = AiUsageRecord::where('invocation_id', 'inv-batch')->sole();

    expect($record->is_batch)->toBeTrue()
        ->and((float) $record->input_per_mtok)->toBe(0.50)
        ->and((float) $record->output_per_mtok)->toBe(2.00);
});

test('batch flag falls back to standard rates when batch columns are zero', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 1.00,
        'output_per_mtok' => 4.00,
        'batch_input_per_mtok' => 0,
        'batch_output_per_mtok' => 0,
    ]);

    resolve(BatchPricingContext::class)->enabled = true;

    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-batch-zero',
        agent: new MediaAgent,
        usage: new TextUsage(inputTokens: 100, outputTokens: 50),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $record = AiUsageRecord::where('invocation_id', 'inv-batch-zero')->sole();

    expect($record->is_batch)->toBeTrue()
        ->and((float) $record->input_per_mtok)->toBe(1.00)
        ->and((float) $record->output_per_mtok)->toBe(4.00);
});

test('non-batch usage is priced with standard rates', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 1.00,
        'output_per_mtok' => 4.00,
        'batch_input_per_mtok' => 0.50,
        'batch_output_per_mtok' => 2.00,
    ]);

    $agentPrompted = makeAgentPrompted(
        invocationId: 'inv-standard',
        agent: new MediaAgent,
        usage: new TextUsage(inputTokens: 100, outputTokens: 50),
        meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
    );

    (new RecordAgentUsage)->handle($agentPrompted);

    $record = AiUsageRecord::where('invocation_id', 'inv-standard')->sole();

    expect($record->is_batch)->toBeFalse()
        ->and((float) $record->input_per_mtok)->toBe(1.00)
        ->and((float) $record->output_per_mtok)->toBe(4.00);
});

test('usage is attributed to the run attribution user when no conversation user exists', function (): void {
    $user = User::factory()->admin()->create();

    resolve(AiRunAttribution::class)->during($user, function (): void {
        (new RecordAgentUsage)->handle(makeAgentPrompted(
            invocationId: 'inv-attr',
            agent: new PriceFetcherAgent,
            usage: new TextUsage(inputTokens: 10, outputTokens: 5),
            meta: new Meta(provider: 'openai', model: 'gpt-5-mini'),
        ));
    });

    expect(AiUsageRecord::where('invocation_id', 'inv-attr')->value('user_id'))->toBe($user->id);
});
