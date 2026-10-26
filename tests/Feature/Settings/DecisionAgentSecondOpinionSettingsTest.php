<?php

declare(strict_types=1);

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Models\AppSetting;
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

test('per-event overrides round-trip sorted, without all-inherit entries', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    expect($decisionAgentSettings->secondOpinionEventOverrides())->toBe([]);

    $decisionAgentSettings->setSecondOpinionEventOverrides([
        'sonarr:Download' => ['mode' => SecondOpinionMode::Off, 'engine' => null, 'jev_threshold' => null],
        'radarr:Grab' => ['mode' => null, 'engine' => SecondOpinionEngine::Jev, 'jev_threshold' => 0.6],
        'sonarr:Grab' => ['mode' => null, 'engine' => null, 'jev_threshold' => null],
    ]);

    expect($decisionAgentSettings->secondOpinionEventOverrides())->toBe([
        'radarr:Grab' => ['mode' => null, 'engine' => SecondOpinionEngine::Jev, 'jev_threshold' => 0.6],
        'sonarr:Download' => ['mode' => SecondOpinionMode::Off, 'engine' => null, 'jev_threshold' => null],
    ])->and(AppSetting::query()->find(DecisionAgentSettings::SECOND_OPINION_EVENT_OVERRIDES_KEY)?->value)->toBe([
        'radarr:Grab' => ['mode' => null, 'engine' => 'jev', 'jev_threshold' => 0.6],
        'sonarr:Download' => ['mode' => 'off', 'engine' => null, 'jev_threshold' => null],
    ]);
});

test('clearing every override forgets the stored key', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setSecondOpinionEventOverrides(['sonarr:Download' => ['mode' => SecondOpinionMode::Hold, 'engine' => null, 'jev_threshold' => null]]);

    $decisionAgentSettings->setSecondOpinionEventOverrides([]);

    expect(AppSetting::query()->whereKey(DecisionAgentSettings::SECOND_OPINION_EVENT_OVERRIDES_KEY)->exists())->toBeFalse()
        ->and($decisionAgentSettings->secondOpinionEventOverrides())->toBe([]);
});

test('an event override wins over the global engine and threshold and leaves other events alone', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setSecondOpinionEngine(SecondOpinionEngine::Llm);
    $decisionAgentSettings->setSecondOpinionJevThreshold(0.8);
    $decisionAgentSettings->setSecondOpinionEventOverrides([
        'sonarr:Download' => ['mode' => SecondOpinionMode::Reject, 'engine' => SecondOpinionEngine::Jev, 'jev_threshold' => 0.0],
    ]);

    expect($decisionAgentSettings->secondOpinionEngine('sonarr:Download'))->toBe(SecondOpinionEngine::Jev)
        ->and($decisionAgentSettings->secondOpinionJevThreshold('sonarr:Download'))->toBe(0.0)
        ->and($decisionAgentSettings->secondOpinionModeOverride('sonarr:Download'))->toBe(SecondOpinionMode::Reject)
        ->and($decisionAgentSettings->hasSecondOpinionEventOverride('sonarr:Download'))->toBeTrue()
        ->and($decisionAgentSettings->secondOpinionEngine('radarr:Grab'))->toBe(SecondOpinionEngine::Llm)
        ->and($decisionAgentSettings->secondOpinionJevThreshold('radarr:Grab'))->toBe(0.8)
        ->and($decisionAgentSettings->secondOpinionModeOverride('radarr:Grab'))->toBeNull()
        ->and($decisionAgentSettings->hasSecondOpinionEventOverride('radarr:Grab'))->toBeFalse()
        ->and($decisionAgentSettings->secondOpinionEngine())->toBe(SecondOpinionEngine::Llm)
        ->and($decisionAgentSettings->secondOpinionModeOverride(null))->toBeNull()
        ->and($decisionAgentSettings->hasSecondOpinionEventOverride(null))->toBeFalse();
});

test('a malformed stored override map is normalised instead of failing', function (mixed $stored, array $expected): void {
    resolve(AppSettings::class)->set(DecisionAgentSettings::SECOND_OPINION_EVENT_OVERRIDES_KEY, $stored);

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEventOverrides())->toBe($expected);
})->with([
    'unknown values and junk entries' => [[
        'sonarr:Download' => ['mode' => 'maybe', 'engine' => 'oracle', 'jev_threshold' => 1.7],
        'radarr:Grab' => 'hold',
        'sonarr:Grab' => ['mode' => 'hold', 'jev_threshold' => 'high'],
        '' => ['mode' => 'off'],
        'radarr:Download' => ['mode' => 'nope', 'engine' => null, 'jev_threshold' => null],
    ], [
        'sonarr:Download' => ['mode' => null, 'engine' => null, 'jev_threshold' => 1.0],
        'sonarr:Grab' => ['mode' => SecondOpinionMode::Hold, 'engine' => null, 'jev_threshold' => null],
    ]],
    'not a map at all' => ['oops', []],
]);
