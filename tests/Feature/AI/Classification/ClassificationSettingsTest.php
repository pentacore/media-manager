<?php

declare(strict_types=1);

use App\Settings\AiSettings;

test('the new classification features default to off with their documented thresholds', function (): void {
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->stuckImportFastPathEnabled())->toBeFalse()
        ->and($aiSettings->stuckImportThreshold())->toBe(0.85)
        ->and($aiSettings->decisionToolScopingEnabled())->toBeFalse()
        ->and($aiSettings->classificationAuditSampleRate())->toBe(0.05);
});

test('the new classification settings persist and clear back to their defaults', function (): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setStuckImportFastPathEnabled(true);
    $aiSettings->setStuckImportThreshold(0.9);
    $aiSettings->setDecisionToolScopingEnabled(true);
    $aiSettings->setClassificationAuditSampleRate(0.1);

    expect($aiSettings->stuckImportFastPathEnabled())->toBeTrue()
        ->and($aiSettings->stuckImportThreshold())->toBe(0.9)
        ->and($aiSettings->decisionToolScopingEnabled())->toBeTrue()
        ->and($aiSettings->classificationAuditSampleRate())->toBe(0.1);

    $aiSettings->setStuckImportThreshold(null);
    $aiSettings->setClassificationAuditSampleRate(null);

    expect($aiSettings->stuckImportThreshold())->toBe(0.85)
        ->and($aiSettings->classificationAuditSampleRate())->toBe(0.05);
});

test('stored thresholds and rates outside zero to one are clamped', function (float $stored, float $expected): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setStuckImportThreshold($stored);
    $aiSettings->setClassificationAuditSampleRate($stored);

    expect($aiSettings->stuckImportThreshold())->toBe($expected)
        ->and($aiSettings->classificationAuditSampleRate())->toBe($expected);
})->with([
    'above one' => [1.7, 1.0],
    'below zero' => [-0.2, 0.0],
]);
