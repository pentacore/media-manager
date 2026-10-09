<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\ActionRequest;
use App\Models\ClassificationOutcome;

function stuckImportRow(): ClassificationOutcome
{
    return ClassificationOutcome::factory()->gate(ClassificationGate::StuckImport)->create([
        'subject_key' => ClassificationOutcome::subjectKey('download', 'sonarr:dl-1'),
        'question' => 'choice',
        'predicted' => 'import',
        'verdict' => ClassificationVerdict::ResolvedByClassifier,
    ]);
}

test('approving a classifier-queued import resolves its outcome as positive', function (ActionRequestStatus $actionRequestStatus, bool $requiresApproval, ?bool $expected): void {
    $row = stuckImportRow();
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'resolve_manual_import',
        'status' => ActionRequestStatus::Pending,
        'requires_approval' => $requiresApproval,
        'payload' => ['service' => 'sonarr', 'download_id' => 'dl-1'],
    ]);

    $actionRequest->update(['status' => $actionRequestStatus]);

    expect($row->refresh()->outcome_positive)->toBe($expected);
})->with([
    'approved by a human' => [ActionRequestStatus::Approved, true, true],
    'rejected by a human' => [ActionRequestStatus::Rejected, true, false],
    'auto-run completed' => [ActionRequestStatus::Completed, false, true],
    'executing says nothing' => [ActionRequestStatus::Executing, false, null],
]);

test('other action types never touch stuck-import outcomes', function (): void {
    $row = stuckImportRow();
    $actionRequest = ActionRequest::factory()->create(['type' => 'add_series', 'status' => ActionRequestStatus::Pending, 'payload' => ['service' => 'sonarr', 'download_id' => 'dl-1']]);

    $actionRequest->update(['status' => ActionRequestStatus::Rejected]);

    expect($row->refresh()->outcome_at)->toBeNull();
});
