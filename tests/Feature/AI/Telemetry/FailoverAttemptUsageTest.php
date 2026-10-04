<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\RunUsageAccumulator;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\Prompt;
use Laravel\Ai\Responses\Data\TextUsage;

function failoverAttemptPrompt(object $agent): AgentPrompt
{
    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, $agent);
    new ReflectionProperty(Prompt::class, 'prompt')->setValue($agentPrompt, 'prompt text');
    new ReflectionProperty(AgentPrompt::class, 'attachments')->setValue($agentPrompt, collect());

    return $agentPrompt;
}

beforeEach(function (): void {
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'input_per_mtok' => 3, 'output_per_mtok' => 15]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1, 'output_per_mtok' => 2]);
});

test('steps completed before a failover are billed to the provider that ran them', function (): void {
    $runUsageAccumulator = resolve(RunUsageAccumulator::class);
    $runUsageAccumulator->add('inv-failover', 'anthropic', 'claude-sonnet-4-5', new TextUsage(3000, 600));

    event(new AgentFailedOver('inv-failover', new DecisionAgent, Ai::textProvider('anthropic'), 'claude-sonnet-4-5', new ProviderOverloadedException('overloaded')));

    $attempt = AiUsageRecord::where('provider', 'anthropic')->sole();

    expect($attempt)
        ->invocation_id->not->toBe('inv-failover')
        ->model->toBe('claude-sonnet-4-5')
        ->prompt_tokens->toBe(3000)
        ->completion_tokens->toBe(600)
        ->status->toBe('failed')
        ->agent_class->toBe(DecisionAgent::class)
        ->error_message->toBe('overloaded')
        ->and($runUsageAccumulator->usage('inv-failover'))->toBeNull();
});

test('a failover with no completed step writes no attempt row', function (): void {
    event(new AgentFailedOver('inv-empty', new DecisionAgent, Ai::textProvider('anthropic'), 'claude-sonnet-4-5', new ProviderOverloadedException('overloaded')));

    expect(AiUsageRecord::count())->toBe(0);
});

test('when every provider fails each attempt is priced at its own model', function (): void {
    $runUsageAccumulator = resolve(RunUsageAccumulator::class);
    $runUsageAccumulator->add('inv-all-failed', 'anthropic', 'claude-sonnet-4-5', new TextUsage(3000, 600));

    event(new AgentFailedOver('inv-all-failed', new DecisionAgent, Ai::textProvider('anthropic'), 'claude-sonnet-4-5', new ProviderOverloadedException('overloaded')));

    $runUsageAccumulator->add('inv-all-failed', 'openai', 'gpt-5-mini', new TextUsage(500, 100));

    event(new AgentFailed('inv-all-failed', failoverAttemptPrompt(new DecisionAgent), new RuntimeException('openai down too')));

    expect(AiUsageRecord::where('invocation_id', 'inv-all-failed')->sole())
        ->provider->toBe('openai')
        ->model->toBe('gpt-5-mini')
        ->prompt_tokens->toBe(500)
        ->completion_tokens->toBe(100)
        ->and(AiUsageRecord::where('provider', 'anthropic')->sole())
        ->prompt_tokens->toBe(3000)
        ->completion_tokens->toBe(600);
});

test('an abandoned provider attempt stores its error without upstream paths or credentials', function (): void {
    resolve(RunUsageAccumulator::class)->add('inv-redact', 'anthropic', 'claude-sonnet-4-5', new TextUsage(100, 10));

    event(new AgentFailedOver('inv-redact', new DecisionAgent, Ai::textProvider('anthropic'), 'claude-sonnet-4-5', new ProviderOverloadedException('overloaded at /srv/gateway/queue via https://gw.example/v1?key=sk-1')));

    expect(AiUsageRecord::where('provider', 'anthropic')->sole()->error_message)
        ->toBe('overloaded at [redacted path] via https://gw.example/v1?[redacted]');
});
