<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Events\ActionRequestStatusChanged;
use App\Events\SubtitleCaseChanged;
use App\Jobs\ReconcileSubtitleCase;
use App\Listeners\UpdateSubtitleCaseFromActionRequest;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $actionOverrides
 * @return array<int, SubtitleCase|Collection<int, SubtitleCase>|ActionRequest|Collection<int, ActionRequest>>
 */
function actionCorrelatedSubtitleCase(
    SubtitleCaseStatus $subtitleCaseStatus,
    string $actionType,
    array $actionOverrides = [],
): array {
    $subtitleCase = SubtitleCase::factory()->create(['status' => $subtitleCaseStatus]);
    $actionRequest = ActionRequest::factory()->create(array_replace([
        'type' => $actionType,
        'status' => ActionRequestStatus::Pending,
        'payload' => ['subtitle_case_id' => $subtitleCase->id],
    ], $actionOverrides));
    $link = $actionType === 'replace_media_file'
        ? 'replacement_action_request_id'
        : 'download_action_request_id';
    $subtitleCase->forceFill([$link => $actionRequest->id])->save();

    return [$subtitleCase, $actionRequest];
}

test('the Action Request correlation listener is discovered', function (): void {
    Event::fake();
    Event::assertListening(
        ActionRequestStatusChanged::class,
        UpdateSubtitleCaseFromActionRequest::class,
    );
});

test('a completed Bazarr download remains requested until inventory confirms the subtitle', function (): void {
    // The listener now schedules targeted reconciliation on completion; the job
    // itself is covered by its own suite, and under the sync queue it would
    // probe Bazarr for real here.
    Queue::fake([ReconcileSubtitleCase::class]);
    [$subtitleCase, $actionRequest] = actionCorrelatedSubtitleCase(
        SubtitleCaseStatus::DownloadRequested,
        'bazarr_download_best',
        [
            'status' => ActionRequestStatus::Completed,
            'result' => ['success' => true],
        ],
    );

    resolve(UpdateSubtitleCaseFromActionRequest::class)->handle(
        new ActionRequestStatusChanged($actionRequest),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);
});

test('a completed Bazarr download schedules targeted reconciliation for its case', function (): void {
    Queue::fake([ReconcileSubtitleCase::class]);
    [$subtitleCase, $actionRequest] = actionCorrelatedSubtitleCase(
        SubtitleCaseStatus::DownloadRequested,
        'bazarr_download_best',
        [
            'status' => ActionRequestStatus::Completed,
            'result' => ['success' => true],
        ],
    );

    resolve(UpdateSubtitleCaseFromActionRequest::class)->handle(
        new ActionRequestStatusChanged($actionRequest),
    );

    // The case still waits for evidence rather than trusting the download, but a
    // targeted reconciliation has to run: the bulk sweep only checks the
    // download outcome for forCase() jobs, and a resolved target drops out of
    // the candidate feed entirely.
    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);

    Queue::assertPushed(
        ReconcileSubtitleCase::class,
        fn (ReconcileSubtitleCase $job): bool => $job->subtitleCaseId === $subtitleCase->id
            && $job->delay !== null,
    );
});

test('a failed per-language download request correlates through the evidence map', function (): void {
    $subtitleCase = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::DownloadRequested,
        'required_languages' => [
            ['code' => 'eng', 'forced' => false, 'hearing_impaired' => false],
            ['code' => 'swe', 'forced' => false, 'hearing_impaired' => false],
        ],
    ]);
    $englishRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Failed,
        'payload' => ['subtitle_case_id' => $subtitleCase->id, 'language' => 'eng'],
        'result' => ['success' => false, 'reason' => 'execution_failed'],
    ]);
    $swedishRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['subtitle_case_id' => $subtitleCase->id, 'language' => 'swe'],
    ]);
    // Swedish was requested last, so the scalar column points at it while the
    // evidence map still records the English request.
    $subtitleCase->forceFill([
        'download_action_request_id' => $swedishRequest->id,
        'evidence' => [
            ...$subtitleCase->evidence,
            'download_requests' => [
                'eng|0|0' => $englishRequest->id,
                'swe|0|0' => $swedishRequest->id,
            ],
        ],
    ])->save();

    resolve(UpdateSubtitleCaseFromActionRequest::class)->handle(
        new ActionRequestStatusChanged($englishRequest),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCase->fresh()->failure_reason)->toContain('eng');
});

test('a failed Bazarr download moves the case to review only once', function (): void {
    [$subtitleCase, $actionRequest] = actionCorrelatedSubtitleCase(
        SubtitleCaseStatus::DownloadRequested,
        'bazarr_download_best',
        [
            'status' => ActionRequestStatus::Failed,
            'result' => ['success' => false, 'reason' => 'execution_failed'],
        ],
    );
    Event::fake([SubtitleCaseChanged::class]);
    $updateSubtitleCaseFromActionRequest = resolve(UpdateSubtitleCaseFromActionRequest::class);
    $event = new ActionRequestStatusChanged($actionRequest);

    $updateSubtitleCaseFromActionRequest->handle($event);
    $updateSubtitleCaseFromActionRequest->handle($event);

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview);
    Event::assertDispatchedOnce(SubtitleCaseChanged::class);
});

test('an indeterminate Bazarr download keeps the case waiting and records the uncertainty once', function (): void {
    [$subtitleCase, $actionRequest] = actionCorrelatedSubtitleCase(
        SubtitleCaseStatus::DownloadRequested,
        'bazarr_download_best',
        [
            'status' => ActionRequestStatus::Failed,
            'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'indeterminate' => true],
        ],
    );
    $actionRequest->forceFill([
        'payload' => ['subtitle_case_id' => $subtitleCase->id, 'language' => 'swe'],
    ])->save();
    $updateSubtitleCaseFromActionRequest = resolve(UpdateSubtitleCaseFromActionRequest::class);
    $event = new ActionRequestStatusChanged($actionRequest);

    $updateSubtitleCaseFromActionRequest->handle($event);
    $updateSubtitleCaseFromActionRequest->handle($event);

    // Parking the case here would strand it: the targeted reconciliation that
    // BazarrActions scheduled only verifies download_requested and
    // bazarr_searching cases, and a satisfied target also drops out of the bulk
    // missing feed, so an accepted-but-unconfirmed subtitle could never resolve.
    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);

    $attempts = SubtitleCaseAttempt::query()
        ->where('subtitle_case_id', $subtitleCase->id)
        ->where('type', SubtitleCaseAttemptType::Download)
        ->get();

    expect($attempts)->toHaveCount(1)
        ->and($attempts->first()->outcome)->toBe(SubtitleCaseAttemptOutcome::Indeterminate)
        ->and($attempts->first()->summary)->toMatchArray(['language' => 'swe']);
});

test('a pending or executing replacement request keeps the case replacement requested', function (
    ActionRequestStatus $actionRequestStatus,
): void {
    [$subtitleCase, $actionRequest] = actionCorrelatedSubtitleCase(
        SubtitleCaseStatus::ReplacementRequested,
        'replace_media_file',
        ['status' => $actionRequestStatus],
    );

    resolve(UpdateSubtitleCaseFromActionRequest::class)->handle(
        new ActionRequestStatusChanged($actionRequest),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested);
})->with([
    ActionRequestStatus::Pending,
    ActionRequestStatus::Executing,
]);

test('a failed replacement request moves the case to review', function (): void {
    [$subtitleCase, $actionRequest] = actionCorrelatedSubtitleCase(
        SubtitleCaseStatus::ReplacementRequested,
        'replace_media_file',
        [
            'status' => ActionRequestStatus::Failed,
            'result' => ['success' => false, 'reason' => 'execution_failed'],
        ],
    );

    resolve(UpdateSubtitleCaseFromActionRequest::class)->handle(
        new ActionRequestStatusChanged($actionRequest),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCase->fresh()->failure_reason)->toBe('replacement_action_failed');
});

test('an action payload cannot update a case linked to a different request', function (): void {
    [$subtitleCase, $actionRequest] = actionCorrelatedSubtitleCase(
        SubtitleCaseStatus::DownloadRequested,
        'bazarr_download_best',
        [
            'status' => ActionRequestStatus::Failed,
            'result' => ['success' => false, 'reason' => 'execution_failed'],
        ],
    );
    $otherActionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Failed,
        'payload' => ['subtitle_case_id' => $subtitleCase->id],
        'result' => ['success' => false, 'reason' => 'execution_failed'],
    ]);

    resolve(UpdateSubtitleCaseFromActionRequest::class)->handle(
        new ActionRequestStatusChanged($otherActionRequest),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested)
        ->and($actionRequest->id)->not->toBe($otherActionRequest->id);
});
