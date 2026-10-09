<?php

declare(strict_types=1);

use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Enums\SubtitleCaseStatus;
use App\Models\ClassificationOutcome;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleCaseLifecycle;

function triageOutcomeCase(SubtitleCaseStatus $subtitleCaseStatus): SubtitleCase
{
    return SubtitleCase::factory()->create([
        'bazarr_connection_id' => ServiceConnection::factory()->bazarr(),
        'service_connection_id' => ServiceConnection::factory()->radarr(),
        'media_type' => 'movie',
        'scope' => 'movie',
        'status' => $subtitleCaseStatus,
        'required_languages' => [['code' => 'eng']],
    ]);
}

function triageRow(SubtitleCase $subtitleCase, ClassificationVerdict $classificationVerdict = ClassificationVerdict::Passed): ClassificationOutcome
{
    return ClassificationOutcome::factory()->gate(ClassificationGate::SubtitleTriage)->create([
        'subject_key' => ClassificationOutcome::subjectKey('subtitle_case', $subtitleCase->id),
        'verdict' => $classificationVerdict,
    ]);
}

test('a resolved case resolves its passed triage outcome as positive', function (): void {
    $subtitleCase = triageOutcomeCase(SubtitleCaseStatus::ReplacementRequested);
    $classificationOutcome = triageRow($subtitleCase);

    resolve(SubtitleCaseLifecycle::class)->resolve($subtitleCase);

    expect($classificationOutcome->refresh())->outcome_positive->toBeTrue()->outcome_detail->toBe('resolved');
});

test('a case sent to review after the advisor ran resolves its audit-run triage outcome as negative', function (): void {
    $subtitleCase = triageOutcomeCase(SubtitleCaseStatus::AdvisorRunning);
    $classificationOutcome = triageRow($subtitleCase, ClassificationVerdict::AuditRun);

    resolve(SubtitleCaseLifecycle::class)->needsReview($subtitleCase, 'No candidate.');

    expect($classificationOutcome->refresh()->outcome_positive)->toBeFalse();
});

test('a skipped triage row is never resolved by later transitions', function (): void {
    $subtitleCase = triageOutcomeCase(SubtitleCaseStatus::NeedsReview);
    $classificationOutcome = triageRow($subtitleCase, ClassificationVerdict::Skipped);

    resolve(SubtitleCaseLifecycle::class)->resolve($subtitleCase);

    expect($classificationOutcome->refresh()->outcome_at)->toBeNull();
});
