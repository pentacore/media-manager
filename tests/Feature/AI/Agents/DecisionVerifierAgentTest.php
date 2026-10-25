<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionVerifierAgent;
use App\Ai\Middleware\ReplayBenchmarkTools;
use App\Ai\TaskModelResolver;
use App\Enums\AiTask;
use Laravel\Ai\Responses\StructuredAgentResponse;

test('the verifier answers with a structured verdict', function (): void {
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'The event concerns another series.']]);

    $structuredAgentResponse = (new DecisionVerifierAgent)->prompt('case file');

    expect($structuredAgentResponse)->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($structuredAgentResponse['agrees'])->toBeFalse()
        ->and($structuredAgentResponse['reason'])->toBe('The event concerns another series.');
});

test('the verifier runs as its own task and ends its middleware with the benchmark replay', function (): void {
    $decisionVerifierAgent = new DecisionVerifierAgent;
    $middleware = $decisionVerifierAgent->middleware();

    expect($decisionVerifierAgent->aiTask())->toBe(AiTask::DecisionVerifier)
        ->and(end($middleware))->toBeInstanceOf(ReplayBenchmarkTools::class);
});

test('without its own selection the verifier follows the chat model', function (): void {
    $taskModelResolver = resolve(TaskModelResolver::class);

    $resolvedSelection = $taskModelResolver->resolve(AiTask::DecisionVerifier);
    $chat = $taskModelResolver->resolve(AiTask::Chat);

    expect([$resolvedSelection->provider, $resolvedSelection->model])->toBe([$chat->provider, $chat->model]);
});
