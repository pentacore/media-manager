<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Enums\SubtitleCaseStatus;
use App\Events\MediaReplacementAttemptChanged;
use App\Events\SubtitleCaseChanged;
use App\Listeners\UpdateSubtitleCaseFromReplacementAttempt;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\SubtitleCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;

/**
 * @return array<int, SubtitleCase|Collection<int, SubtitleCase>|ActionRequest|Collection<int, ActionRequest>|Collection<int, MediaReplacementAttempt>|MediaReplacementAttempt>
 */
function replacementCorrelatedSubtitleCase(
    MediaReplacementStatus $mediaReplacementStatus,
    SubtitleCaseStatus $subtitleCaseStatus = SubtitleCaseStatus::ReplacementRequested,
): array {
    $subtitleCase = SubtitleCase::factory()->create(['status' => $subtitleCaseStatus]);
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'payload' => ['subtitle_case_id' => $subtitleCase->id],
    ]);
    $subtitleCase->forceFill(['replacement_action_request_id' => $actionRequest->id])->save();
    $mediaReplacementAttempt = MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'service_connection_id' => $subtitleCase->service_connection_id,
        'status' => $mediaReplacementStatus,
    ]);

    return [$subtitleCase, $actionRequest, $mediaReplacementAttempt];
}

function replacementAttemptChangedEvent(
    MediaReplacementAttempt $mediaReplacementAttempt,
): MediaReplacementAttemptChanged {
    return new MediaReplacementAttemptChanged($mediaReplacementAttempt);
}

test('the replacement attempt correlation listener is discovered', function (): void {
    Event::fake();
    Event::assertListening(
        MediaReplacementAttemptChanged::class,
        UpdateSubtitleCaseFromReplacementAttempt::class,
    );
});

test('a verified replacement resolves its correlated subtitle case', function (): void {
    [$subtitleCase, , $mediaReplacementAttempt] = replacementCorrelatedSubtitleCase(
        MediaReplacementStatus::Verified,
    );

    resolve(UpdateSubtitleCaseFromReplacementAttempt::class)->handle(
        replacementAttemptChangedEvent($mediaReplacementAttempt),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::Resolved)
        ->and($subtitleCase->fresh()->resolved_at)->not->toBeNull();
});

test('a failed or needs-attention replacement moves its case to review only once', function (
    MediaReplacementStatus $mediaReplacementStatus,
): void {
    [$subtitleCase, , $mediaReplacementAttempt] = replacementCorrelatedSubtitleCase(
        $mediaReplacementStatus,
    );
    Event::fake([SubtitleCaseChanged::class]);
    $updateSubtitleCaseFromReplacementAttempt = resolve(UpdateSubtitleCaseFromReplacementAttempt::class);
    $mediaReplacementAttemptChanged = replacementAttemptChangedEvent($mediaReplacementAttempt);

    $updateSubtitleCaseFromReplacementAttempt->handle($mediaReplacementAttemptChanged);
    $updateSubtitleCaseFromReplacementAttempt->handle($mediaReplacementAttemptChanged);

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCase->fresh()->failure_reason)->toBe(
            $mediaReplacementStatus === MediaReplacementStatus::Failed
                ? 'replacement_failed'
                : 'replacement_needs_attention',
        );
    Event::assertDispatchedOnce(SubtitleCaseChanged::class);
})->with([
    MediaReplacementStatus::Failed,
    MediaReplacementStatus::NeedsAttention,
]);

test('a late verified import resolves a case an earlier needs-attention parked', function (): void {
    [$subtitleCase, , $mediaReplacementAttempt] = replacementCorrelatedSubtitleCase(
        MediaReplacementStatus::NeedsAttention,
    );
    $updateSubtitleCaseFromReplacementAttempt = resolve(UpdateSubtitleCaseFromReplacementAttempt::class);

    $updateSubtitleCaseFromReplacementAttempt->handle(replacementAttemptChangedEvent($mediaReplacementAttempt));

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview);

    // The tracker allows this recovery for download_timeout,
    // manual_interaction_required and deletion_failed; the correlated case must
    // follow instead of staying flagged forever.
    $mediaReplacementAttempt->forceFill(['status' => MediaReplacementStatus::Verified])->save();
    $updateSubtitleCaseFromReplacementAttempt->handle(replacementAttemptChangedEvent($mediaReplacementAttempt));

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::Resolved)
        ->and($subtitleCase->fresh()->resolved_at)->not->toBeNull()
        ->and($subtitleCase->fresh()->failure_reason)->toBeNull();
});

test('a late verified import does not reopen an explicitly closed case', function (
    SubtitleCaseStatus $subtitleCaseStatus,
): void {
    [$subtitleCase, , $mediaReplacementAttempt] = replacementCorrelatedSubtitleCase(
        MediaReplacementStatus::Verified,
        $subtitleCaseStatus,
    );

    resolve(UpdateSubtitleCaseFromReplacementAttempt::class)->handle(
        replacementAttemptChangedEvent($mediaReplacementAttempt),
    );

    expect($subtitleCase->fresh()->status)->toBe($subtitleCaseStatus);
})->with([
    SubtitleCaseStatus::Dismissed,
    SubtitleCaseStatus::Handled,
]);

test('a replacement attempt cannot update a case linked to another action request', function (): void {
    [$subtitleCase, , $mediaReplacementAttempt] = replacementCorrelatedSubtitleCase(
        MediaReplacementStatus::Verified,
    );
    $otherActionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'payload' => ['subtitle_case_id' => $subtitleCase->id],
    ]);
    $mediaReplacementAttempt->forceFill(['action_request_id' => $otherActionRequest->id])->save();

    resolve(UpdateSubtitleCaseFromReplacementAttempt::class)->handle(
        replacementAttemptChangedEvent($mediaReplacementAttempt),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested);
});

test('replacement outcomes do not reopen terminal subtitle cases', function (): void {
    [$subtitleCase, , $mediaReplacementAttempt] = replacementCorrelatedSubtitleCase(
        MediaReplacementStatus::NeedsAttention,
        SubtitleCaseStatus::Handled,
    );

    resolve(UpdateSubtitleCaseFromReplacementAttempt::class)->handle(
        replacementAttemptChangedEvent($mediaReplacementAttempt),
    );

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::Handled);
});
