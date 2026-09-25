<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Listeners\Ai\RecordFailedAgentRun;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\RunUsageAccumulator;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;

function makeFailedRunPrompt(object $agent): AgentPrompt
{
    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, $agent);

    return $agentPrompt;
}

test('a run that fails after a completed step bills the completed step as failed', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1, 'output_per_mtok' => 2]);

    $calls = 0;
    DecisionAgent::fake(function () use (&$calls): mixed {
        $calls++;

        return $calls === 1
            ? new ToolCall(id: 'c1', name: 'GetServiceStatusTool', arguments: [])
            : throw new RuntimeException('provider fell over');
    });

    // Registered after the discovered AccumulateStepUsage, so it observes the
    // accumulator once the completed step has been added.
    $accumulatedModels = [];
    Event::listen(StepCompleted::class, function (StepCompleted $stepCompleted) use (&$accumulatedModels): void {
        $accumulatedModels[] = resolve(RunUsageAccumulator::class)->model($stepCompleted->invocationId);
    });

    // A faked ToolCall step carries zero usage, so this pins the row and error;
    // the next test pins partial-token pricing via the accumulator directly.
    expect(fn () => (new DecisionAgent)->prompt('event'))->toThrow(RuntimeException::class);

    expect($accumulatedModels)->not->toBeEmpty()->each->toBeString();

    $row = AiUsageRecord::latest('id')->firstOrFail();

    expect($row->status)->toBe('failed')
        ->and($row->error_message)->toContain('provider fell over')
        ->and($row->agent_class)->toBe(DecisionAgent::class)
        ->and(resolve(RunUsageAccumulator::class)->usage($row->invocation_id))->toBeNull();
});

test('a failed run with accumulated usage prices the partial tokens', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1, 'output_per_mtok' => 2]);

    $runUsageAccumulator = resolve(RunUsageAccumulator::class);
    $runUsageAccumulator->add('inv-partial', 'openai', 'gpt-5-mini', new TextUsage(1000, 200));

    (new RecordFailedAgentRun)->handle(new AgentFailed('inv-partial', makeFailedRunPrompt(new DecisionAgent), new RuntimeException('late failure')));

    expect(AiUsageRecord::where('invocation_id', 'inv-partial')->sole())
        ->prompt_tokens->toBe(1000)
        ->completion_tokens->toBe(200)
        ->status->toBe('failed')
        ->provider->toBe('openai')
        ->model->toBe('gpt-5-mini')
        ->price_source->toBe('live')
        ->error_message->toBe('late failure');

    expect($runUsageAccumulator->usage('inv-partial'))->toBeNull();
});

test('a successful run discards its accumulator entry', function (): void {
    DecisionAgent::fake([new TextResponse('ok', new TextUsage(10, 5), new Meta('openai', 'gpt-5-mini'))]);

    $response = (new DecisionAgent)->prompt('event');

    expect(resolve(RunUsageAccumulator::class)->usage($response->invocationId))->toBeNull()
        ->and(AiUsageRecord::where('invocation_id', $response->invocationId)->value('status'))->toBe('success');
});
