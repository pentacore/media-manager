<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkScoringType;
use App\Enums\ClassificationGate;
use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;

test('second opinion modes carry their labels', function (): void {
    expect(SecondOpinionMode::Off->label())->toBe('Off')
        ->and(SecondOpinionMode::Hold->label())->toBe('Hold if it disagrees')
        ->and(SecondOpinionMode::Reject->label())->toBe('Reject if it disagrees')
        ->and(SecondOpinionMode::values())->toBe(['off', 'hold', 'reject']);
});

test('only the jev engines use the classifier', function (): void {
    expect(SecondOpinionEngine::Llm->usesClassifier())->toBeFalse()
        ->and(SecondOpinionEngine::JevThenLlm->usesClassifier())->toBeTrue()
        ->and(SecondOpinionEngine::Jev->usesClassifier())->toBeTrue()
        ->and(SecondOpinionEngine::Llm->label())->toBe('LLM')
        ->and(SecondOpinionEngine::JevThenLlm->label())->toBe('Jev, then LLM on doubt')
        ->and(SecondOpinionEngine::Jev->label())->toBe('Jev decides');
});

test('every engine but jev decides can ask the llm', function (): void {
    expect(SecondOpinionEngine::Llm->canAskLlm())->toBeTrue()
        ->and(SecondOpinionEngine::JevThenLlm->canAskLlm())->toBeTrue()
        ->and(SecondOpinionEngine::Jev->canAskLlm())->toBeFalse();
});

test('the decision verifier task follows chat, takes tiers and is benchmarked by verdict', function (): void {
    expect(AiTask::DecisionVerifier->value)->toBe('decision_verifier')
        ->and(AiTask::DecisionVerifier->label())->toBe('Decision verifier')
        ->and(AiTask::DecisionVerifier->inheritsChatModel())->toBeTrue()
        ->and(AiTask::DecisionVerifier->allowsTiers())->toBeTrue()
        ->and(AiTask::DecisionVerifier->hasReasoning())->toBeTrue()
        ->and(AiTask::DecisionVerifier->isBenchmarkable())->toBeTrue()
        ->and(AiTask::DecisionVerifier->benchmarkScoringType())->toBe(BenchmarkScoringType::Verdict)
        ->and(AiTask::DecisionVerifier->acceptsEventScopes())->toBeTrue()
        ->and(AiTask::Failover->isBenchmarkable())->toBeFalse()
        ->and(AiTask::BenchmarkJudge->isBenchmarkable())->toBeFalse()
        ->and(BenchmarkScoringType::Verdict->value)->toBe('verdict')
        ->and(BenchmarkScoringType::Verdict->label())->toBe('Verdict');
});

test('the second opinion gate has a label', function (): void {
    expect(ClassificationGate::SecondOpinion->value)->toBe('second_opinion')
        ->and(ClassificationGate::SecondOpinion->label())->toBe('Decision second opinion');
});
