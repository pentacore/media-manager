<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Middleware\ClientDisconnectedException;
use App\Listeners\Ai\RecordFailedAgentRun;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\AiUsageReporting;
use App\Services\AiUsage\RunUsageAccumulator;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\Prompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Error;

function makeFailedRunPrompt(object $agent, string $promptText = 'prompt text'): AgentPrompt
{
    $agentPrompt = new ReflectionClass(AgentPrompt::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AgentPrompt::class, 'agent')->setValue($agentPrompt, $agent);
    new ReflectionProperty(Prompt::class, 'prompt')->setValue($agentPrompt, $promptText);
    new ReflectionProperty(AgentPrompt::class, 'attachments')->setValue($agentPrompt, collect());

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
    expect(fn (): AgentResponse => (new DecisionAgent)->prompt('event'))->toThrow(RuntimeException::class);

    expect($accumulatedModels)->not->toBeEmpty()->each->toBeString();

    $row = AiUsageRecord::latest('id')->firstOrFail();

    expect($row->status)->toBe('failed')
        ->and($row->error_message)->toContain('provider fell over')
        ->and($row->agent_class)->toBe(DecisionAgent::class)
        ->and($row->prompt_text)->toBe('event')
        ->and($row->response_text)->toBeNull()
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

    $agentResponse = (new DecisionAgent)->prompt('event');

    expect(resolve(RunUsageAccumulator::class)->usage($agentResponse->invocationId))->toBeNull()
        ->and(AiUsageRecord::where('invocation_id', $agentResponse->invocationId)->value('status'))->toBe('success');
});

test('a failed run stores its error message without upstream paths or credentials', function (): void {
    resolve(RunUsageAccumulator::class)->add('inv-redact', 'openai', 'gpt-5-mini', new TextUsage(10, 5));

    (new RecordFailedAgentRun)->handle(new AgentFailed(
        'inv-redact',
        makeFailedRunPrompt(new DecisionAgent),
        new RuntimeException('read /etc/ai/key.json from https://gw.example/v1?token=abc123'),
    ));

    expect(AiUsageRecord::where('invocation_id', 'inv-redact')->sole()->error_message)
        ->toBe('read [redacted path] from https://gw.example/v1?[redacted]');
});

test('a failed run with an empty error message blames the agent, not an upstream service', function (): void {
    resolve(RunUsageAccumulator::class)->add('inv-empty', 'openai', 'gpt-5-mini', new TextUsage(10, 5));

    (new RecordFailedAgentRun)->handle(new AgentFailed(
        'inv-empty',
        makeFailedRunPrompt(new DecisionAgent),
        new RuntimeException(''),
    ));

    expect(AiUsageRecord::where('invocation_id', 'inv-empty')->sole()->error_message)
        ->toBe('The agent failed without a usable description.');
});

test('a run ended by a client disconnect bills its completed steps as stopped without an error', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1, 'output_per_mtok' => 2]);

    $runUsageAccumulator = resolve(RunUsageAccumulator::class);
    $runUsageAccumulator->add('inv-stopped', 'openai', 'gpt-5-mini', new TextUsage(1000, 200));

    (new RecordFailedAgentRun)->handle(new AgentFailed('inv-stopped', makeFailedRunPrompt(new DecisionAgent), new ClientDisconnectedException));

    expect(AiUsageRecord::where('invocation_id', 'inv-stopped')->sole())
        ->prompt_tokens->toBe(1000)
        ->completion_tokens->toBe(200)
        ->status->toBe('stopped')
        ->error_message->toBeNull();
});

test('a run ended by a wrapped client disconnect is stopped too', function (): void {
    (new RecordFailedAgentRun)->handle(new AgentFailed(
        'inv-wrapped',
        makeFailedRunPrompt(new DecisionAgent),
        new RuntimeException('tool failed', 0, new ClientDisconnectedException),
    ));

    expect(AiUsageRecord::where('invocation_id', 'inv-wrapped')->sole())
        ->status->toBe('stopped')
        ->error_message->toBeNull();
});

test('a failed run stores the step that failed and an error code from the exception', function (): void {
    $calls = 0;
    DecisionAgent::fake(function () use (&$calls): mixed {
        $calls++;

        return $calls === 1
            ? new ToolCall(id: 'c1', name: 'GetServiceStatusTool', arguments: [])
            : throw new RuntimeException('provider fell over');
    });

    expect(fn (): AgentResponse => (new DecisionAgent)->prompt('event'))->toThrow(RuntimeException::class);

    expect(AiUsageRecord::latest('id')->firstOrFail())
        ->failed_step->toBe(2)
        ->error_code->toBe('runtime_exception');
});

test('a failed run stores the error type the provider sent inside the stream', function (): void {
    resolve(RunUsageAccumulator::class)->markFailedStep('inv-stream-code', 3);

    (new RecordFailedAgentRun)->handle(new AgentFailed('inv-stream-code', makeFailedRunPrompt(new DecisionAgent), new StreamErrorException(
        new Error('evt-1', 'server_error', 'An error occurred.', false, 1_700_000_000),
    )));

    expect(AiUsageRecord::where('invocation_id', 'inv-stream-code')->sole())
        ->error_code->toBe('server_error')
        ->failed_step->toBe(3)
        ->and(resolve(RunUsageAccumulator::class)->failedStep('inv-stream-code'))->toBeNull();
});

test('a stopped run stores no error code', function (): void {
    (new RecordFailedAgentRun)->handle(new AgentFailed('inv-stopped-code', makeFailedRunPrompt(new DecisionAgent), new ClientDisconnectedException));

    expect(AiUsageRecord::where('invocation_id', 'inv-stopped-code')->sole())
        ->status->toBe('stopped')
        ->error_code->toBeNull();
});

test('the invocation detail carries the failed step and error code', function (): void {
    $aiUsageRecord = AiUsageRecord::factory()->failed()->create(['error_code' => 'server_error', 'failed_step' => 2])->refresh();

    $detail = resolve(AiUsageReporting::class)->invocationDetail($aiUsageRecord);

    expect($detail['record']['error_code'])->toBe('server_error')
        ->and($detail['record']['failed_step'])->toBe(2);
});
