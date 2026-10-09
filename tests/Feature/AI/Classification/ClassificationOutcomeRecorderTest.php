<?php

declare(strict_types=1);

use App\Ai\Classification\ClassificationOutcomeRecorder;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\ClassificationOutcome;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Lottery;

afterEach(function (): void {
    Lottery::determineResultsNormally();
});

test('record writes one open outcome row', function (): void {
    resolve(ClassificationOutcomeRecorder::class)->record(
        ClassificationGate::DecisionGate,
        'decision:7',
        'decision',
        0.42,
        ClassificationVerdict::Passed,
        threshold: 0.3,
    );

    expect(ClassificationOutcome::sole())
        ->gate->toBe(ClassificationGate::DecisionGate)
        ->subject_key->toBe('decision:7')
        ->question->toBe('decision')
        ->probability->toBe(0.42)
        ->threshold->toBe(0.3)
        ->verdict->toBe(ClassificationVerdict::Passed)
        ->outcome_positive->toBeNull()
        ->outcome_at->toBeNull();
});

test('resolve fills only open rows with a matching verdict', function (): void {
    $passed = ClassificationOutcome::factory()->create(['gate' => ClassificationGate::DecisionGate, 'subject_key' => 'decision:7', 'verdict' => ClassificationVerdict::Passed]);
    $skipped = ClassificationOutcome::factory()->create(['gate' => ClassificationGate::DecisionGate, 'subject_key' => 'decision:7', 'verdict' => ClassificationVerdict::Skipped]);
    $other = ClassificationOutcome::factory()->create(['gate' => ClassificationGate::DecisionGate, 'subject_key' => 'decision:8', 'verdict' => ClassificationVerdict::Passed]);

    resolve(ClassificationOutcomeRecorder::class)->resolve(
        ClassificationGate::DecisionGate,
        'decision:7',
        true,
        '1 action(s) proposed',
        [ClassificationVerdict::Passed, ClassificationVerdict::AuditRun],
    );

    expect($passed->refresh())->outcome_positive->toBeTrue()->outcome_detail->toBe('1 action(s) proposed')->outcome_at->not->toBeNull()
        ->and($skipped->refresh()->outcome_at)->toBeNull()
        ->and($other->refresh()->outcome_at)->toBeNull();
});

test('resolve with a predicted filter leaves a row with a different prediction open', function (): void {
    $importRow = ClassificationOutcome::factory()->create(['gate' => ClassificationGate::StuckImport, 'subject_key' => 'download:sonarr:dl-1', 'verdict' => ClassificationVerdict::ResolvedByClassifier, 'predicted' => 'import']);
    $manualRow = ClassificationOutcome::factory()->create(['gate' => ClassificationGate::StuckImport, 'subject_key' => 'download:sonarr:dl-1', 'verdict' => ClassificationVerdict::ResolvedByClassifier, 'predicted' => 'manual']);

    resolve(ClassificationOutcomeRecorder::class)->resolve(
        ClassificationGate::StuckImport,
        'download:sonarr:dl-1',
        true,
        'approved',
        [ClassificationVerdict::ResolvedByClassifier],
        predicted: 'import',
    );

    expect($importRow->refresh())->outcome_positive->toBeTrue()->outcome_at->not->toBeNull()
        ->and($manualRow->refresh()->outcome_at)->toBeNull();
});

test('resolve never overwrites an outcome that is already filled', function (): void {
    $row = ClassificationOutcome::factory()->resolved(false)->create(['gate' => ClassificationGate::StuckImport, 'subject_key' => 'download:sonarr:dl-1', 'verdict' => ClassificationVerdict::ResolvedByClassifier]);

    resolve(ClassificationOutcomeRecorder::class)->resolve(ClassificationGate::StuckImport, 'download:sonarr:dl-1', true, 'approved', [ClassificationVerdict::ResolvedByClassifier]);

    expect($row->refresh()->outcome_positive)->toBeFalse();
});

test('resolveAgainst compares each open row prediction with the actual result', function (): void {
    $row = ClassificationOutcome::factory()->create(['gate' => ClassificationGate::StuckImport, 'subject_key' => 'download:sonarr:dl-1', 'verdict' => ClassificationVerdict::Fallback, 'predicted' => 'import']);

    resolve(ClassificationOutcomeRecorder::class)->resolveAgainst(ClassificationGate::StuckImport, 'download:sonarr:dl-1', 'remove', [ClassificationVerdict::Fallback]);

    expect($row->refresh())->outcome_positive->toBeFalse()->outcome_detail->toBe('actual: remove');
});

test('a failing write never reaches the caller', function (): void {
    Schema::drop('classification_outcomes');

    resolve(ClassificationOutcomeRecorder::class)->record(ClassificationGate::DecisionGate, 'decision:1', 'decision', 0.5, ClassificationVerdict::Passed);
    resolve(ClassificationOutcomeRecorder::class)->resolve(ClassificationGate::DecisionGate, 'decision:1', true, 'x', [ClassificationVerdict::Passed]);
})->throwsNoExceptions();

test('audit sampling follows the lottery and never runs at a zero rate', function (): void {
    $classificationOutcomeRecorder = resolve(ClassificationOutcomeRecorder::class);

    Lottery::alwaysWin();
    expect($classificationOutcomeRecorder->shouldAudit())->toBeTrue();

    Lottery::alwaysLose();
    expect($classificationOutcomeRecorder->shouldAudit())->toBeFalse();

    Lottery::alwaysWin();
    resolve(AiSettings::class)->setClassificationAuditSampleRate(0.0);
    expect($classificationOutcomeRecorder->shouldAudit())->toBeFalse();
});

test('rows older than the retention window are pruned', function (): void {
    config()->set('mediamanager.retention.classification_outcomes_days', 90);
    $old = ClassificationOutcome::factory()->create(['created_at' => now()->subDays(91)]);
    $recent = ClassificationOutcome::factory()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('model:prune', ['--model' => [ClassificationOutcome::class]])->assertSuccessful();

    expect(ClassificationOutcome::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(ClassificationOutcome::query()->whereKey($old->id)->exists())->toBeFalse();
});

test('a zero retention window keeps every row', function (): void {
    config()->set('mediamanager.retention.classification_outcomes_days', 0);
    ClassificationOutcome::factory()->create(['created_at' => now()->subDays(900)]);

    $this->artisan('model:prune', ['--model' => [ClassificationOutcome::class]])->assertSuccessful();

    expect(ClassificationOutcome::count())->toBe(1);
});
