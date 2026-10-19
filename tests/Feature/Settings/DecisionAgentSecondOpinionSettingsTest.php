<?php

declare(strict_types=1);

use App\Enums\SecondOpinionEngine;
use App\Settings\AppSettings;
use App\Settings\DecisionAgentSettings;

test('the second opinion engine defaults to the llm and round-trips', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);

    expect($decisionAgentSettings->secondOpinionEngine())->toBe(SecondOpinionEngine::Llm);

    $decisionAgentSettings->setSecondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    expect($decisionAgentSettings->secondOpinionEngine())->toBe(SecondOpinionEngine::JevThenLlm);

    $decisionAgentSettings->setSecondOpinionEngine(null);
    expect($decisionAgentSettings->secondOpinionEngine())->toBe(SecondOpinionEngine::Llm);
});

test('an unknown stored engine falls back to the llm', function (): void {
    resolve(AppSettings::class)->set(DecisionAgentSettings::SECOND_OPINION_ENGINE_KEY, 'oracle');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEngine())->toBe(SecondOpinionEngine::Llm);
});

test('the jev threshold defaults to 0.8 and is clamped to 0..1', function (float $stored, float $expected): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    expect($decisionAgentSettings->secondOpinionJevThreshold())->toBe(0.8);

    $decisionAgentSettings->setSecondOpinionJevThreshold($stored);
    expect($decisionAgentSettings->secondOpinionJevThreshold())->toBe($expected);
})->with([
    'inside' => [0.65, 0.65],
    'above' => [1.4, 1.0],
    'below' => [-0.2, 0.0],
]);

test('a non-numeric jev threshold falls back to the default', function (string $source): void {
    if ($source === 'store') {
        resolve(AppSettings::class)->set(DecisionAgentSettings::SECOND_OPINION_JEV_THRESHOLD_KEY, 'high');
    } else {
        config(['mediamanager.decision_agent.second_opinion_jev_threshold' => 'high']);
    }

    expect(resolve(DecisionAgentSettings::class)->secondOpinionJevThreshold())->toBe(DecisionAgentSettings::DEFAULT_SECOND_OPINION_JEV_THRESHOLD);
})->with(['store', 'config']);
