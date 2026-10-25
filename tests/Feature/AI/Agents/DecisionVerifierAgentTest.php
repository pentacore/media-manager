<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionVerifierAgent;
use App\Ai\Middleware\ReplayBenchmarkTools;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use Laravel\Ai\Enums\Lab;
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

test('the verifier resolves its model for the event it checks', function (): void {
    AiTaskModel::factory()->task(AiTask::DecisionVerifier)->selecting('openai', 'gpt-verifier')->create();
    AiTaskModel::factory()->event('sonarr:Download', AiTask::DecisionVerifier)->selecting('openai', 'gpt-download-verifier')->reasoning(AiReasoningLevel::None)->create();

    expect((new DecisionVerifierAgent)->forEvent('sonarr:Download')->modelSelection()->model)->toBe('gpt-download-verifier')
        ->and((new DecisionVerifierAgent)->forEvent('sonarr:Download')->providerOptions(Lab::OpenAI)['reasoning']['effort'])->toBe('none')
        ->and((new DecisionVerifierAgent)->forEvent('radarr:Grab')->modelSelection()->model)->toBe('gpt-verifier')
        ->and((new DecisionVerifierAgent)->forEvent(null)->modelSelection()->model)->toBe('gpt-verifier');
});
