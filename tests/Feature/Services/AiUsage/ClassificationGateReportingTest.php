<?php

declare(strict_types=1);

use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\ClassificationOutcome;
use App\Services\AiUsage\ClassificationGateReporting;
use App\Settings\AiSettings;

test('the summary has one entry per gate with ten probability bands', function (): void {
    $summary = resolve(ClassificationGateReporting::class)->summary(null);

    expect(array_column($summary, 'gate'))->toBe(array_map(static fn (ClassificationGate $gate): string => $gate->value, ClassificationGate::cases()))
        ->and($summary[0]['bands'])->toHaveCount(10)
        ->and($summary[0]['bands'][0]['label'])->toBe('0–10%');
});

test('bands count rows and the positive rate among resolved rows', function (): void {
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->resolved(true)->create(['probability' => 0.92, 'verdict' => ClassificationVerdict::Passed]);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->resolved(false)->create(['probability' => 0.95, 'verdict' => ClassificationVerdict::Passed]);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->create(['probability' => 0.99, 'verdict' => ClassificationVerdict::Passed]);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->create(['probability' => 1.0, 'verdict' => ClassificationVerdict::Passed]);

    $gate = collect(resolve(ClassificationGateReporting::class)->summary(null))->firstWhere('gate', 'decision_gate');

    expect($gate['bands'][9])->count->toBe(4)->resolved->toBe(2)->positive_rate->toBe(0.5)
        ->and($gate['bands'][0]['positive_rate'])->toBeNull()
        ->and($gate['total'])->toBe(4);
});

test('the summary reports audit runs, verdict counts and the current threshold', function (): void {
    resolve(AiSettings::class)->setDecisionGateThreshold(0.4);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->resolved(true)->create(['probability' => 0.1, 'verdict' => ClassificationVerdict::AuditRun]);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->create(['probability' => 0.1, 'verdict' => ClassificationVerdict::Skipped]);

    $gate = collect(resolve(ClassificationGateReporting::class)->summary(null))->firstWhere('gate', 'decision_gate');

    expect($gate)->threshold->toBe(0.4)->audit_runs->toBe(1)->audit_positive->toBe(1)
        ->and($gate['verdicts'])->toBe(['skipped' => 1, 'audit_run' => 1]);
});

test('rows before the window are left out', function (): void {
    ClassificationOutcome::factory()->create(['created_at' => now()->subDays(40)]);

    $gate = collect(resolve(ClassificationGateReporting::class)->summary(now()->subDays(7)->toImmutable()))->firstWhere('gate', 'decision_gate');

    expect($gate['total'])->toBe(0);
});
