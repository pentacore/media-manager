<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\RunUsageAccumulator;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Ai;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;

/**
 * A usage or failure listener runs inside the SDK call (the failure events
 * fire inside laravel/ai's own catch), so a write that throws is reported and
 * swallowed: on the failure path it would replace the provider's error, on
 * the success path it would fail a call that completed.
 */
function telemetryGuardClassify(): ClassificationResponse
{
    return Classification::of('payload')
        ->questions(['decision' => new Boolean('Needs action?')])
        ->classify();
}

beforeEach(function (): void {
    Exceptions::fake();

    AiUsageRecord::creating(static fn (): never => throw new LogicException('usage write failed'));
    AiToolInvocation::creating(static fn (): never => throw new LogicException('tool write failed'));
});

test('a completed agent run whose usage write throws still returns its response', function (): void {
    DecisionAgent::fake(['done']);

    expect((new DecisionAgent)->prompt('event')->text)->toBe('done');

    Exceptions::assertReported(LogicException::class);
});

test('a failed agent run whose usage write throws keeps the provider error', function (): void {
    DecisionAgent::fake(fn (): never => throw new RuntimeException('provider fell over'));

    expect(fn (): AgentResponse => (new DecisionAgent)->prompt('event'))
        ->toThrow(RuntimeException::class, 'provider fell over');

    Exceptions::assertReported(LogicException::class);
});

test('a completed embeddings call whose usage write throws still returns its response', function (): void {
    Embeddings::fake();

    expect(Embeddings::for(['Dune'])->generate())->toBeInstanceOf(EmbeddingsResponse::class);

    Exceptions::assertReported(LogicException::class);
});

test('a failed embeddings call whose usage write throws keeps the provider error', function (): void {
    Embeddings::fake(fn (): never => throw new RuntimeException('embeddings down'));

    expect(fn (): EmbeddingsResponse => Embeddings::for(['Dune'])->generate())
        ->toThrow(RuntimeException::class, 'embeddings down');

    Exceptions::assertReported(LogicException::class);
});

test('a completed rerank call whose usage write throws still returns its response', function (): void {
    Reranking::fake();

    expect(Reranking::of(['a', 'b'])->rerank('q'))->toBeInstanceOf(RerankingResponse::class);

    Exceptions::assertReported(LogicException::class);
});

test('a failed rerank call whose usage write throws keeps the provider error', function (): void {
    Reranking::fake(fn (): never => throw new RuntimeException('rerank down'));

    expect(fn (): RerankingResponse => Reranking::of(['a', 'b'])->rerank('q'))
        ->toThrow(RuntimeException::class, 'rerank down');

    Exceptions::assertReported(LogicException::class);
});

test('a completed classification call whose usage write throws still returns its response', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.82)]]);

    expect(telemetryGuardClassify())->toBeInstanceOf(ClassificationResponse::class);

    Exceptions::assertReported(LogicException::class);
});

test('a failed classification call whose usage write throws keeps the provider error', function (): void {
    Classification::fake(fn (): never => throw new RuntimeException('classification down'));

    expect(telemetryGuardClassify(...))->toThrow(RuntimeException::class, 'classification down');

    Exceptions::assertReported(LogicException::class);
});

test('a failover whose attempt usage write throws is reported, not thrown, and still clears the attempt', function (): void {
    $runUsageAccumulator = resolve(RunUsageAccumulator::class);
    $runUsageAccumulator->add('inv-failover', 'anthropic', 'claude-sonnet-4-5', new TextUsage(3000, 600));

    event(new AgentFailedOver('inv-failover', new DecisionAgent, Ai::textProvider('anthropic'), 'claude-sonnet-4-5', new ProviderOverloadedException('overloaded')));

    Exceptions::assertReported(LogicException::class);
    expect($runUsageAccumulator->usage('inv-failover'))->toBeNull();
});

test('a failed tool call whose row write throws is reported, not thrown', function (): void {
    event(new ToolFailed(
        invocationId: 'inv-f',
        toolInvocationId: 't1',
        agent: new MediaAgent,
        tool: resolve(SearchMediaTool::class),
        arguments: [],
        exception: new RuntimeException('boom'),
        time: 5.2,
    ));

    Exceptions::assertReported(LogicException::class);
});
