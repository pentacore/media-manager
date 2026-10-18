<?php

declare(strict_types=1);

use App\Enums\AiTask;
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

test('the decision verifier task follows chat, takes tiers and is not benchmarkable', function (): void {
    expect(AiTask::DecisionVerifier->value)->toBe('decision_verifier')
        ->and(AiTask::DecisionVerifier->label())->toBe('Decision verifier')
        ->and(AiTask::DecisionVerifier->inheritsChatModel())->toBeTrue()
        ->and(AiTask::DecisionVerifier->allowsTiers())->toBeTrue()
        ->and(AiTask::DecisionVerifier->hasReasoning())->toBeTrue()
        ->and(AiTask::DecisionVerifier->isBenchmarkable())->toBeFalse()
        ->and(AiTask::DecisionVerifier->acceptsEventScopes())->toBeFalse();
});

test('the second opinion gate has a label', function (): void {
    expect(ClassificationGate::SecondOpinion->value)->toBe('second_opinion')
        ->and(ClassificationGate::SecondOpinion->label())->toBe('Decision second opinion');
});
