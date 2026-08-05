<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\MediaReplacementStatus;
use App\Enums\SubtitleCaseStatus;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleCaseLifecycle;

final readonly class UpdateSubtitleCaseFromReplacementAttempt
{
    public function __construct(
        private SubtitleCaseLifecycle $subtitleCaseLifecycle,
    ) {}

    public function handle(MediaReplacementAttemptChanged $mediaReplacementAttemptChanged): void
    {
        $mediaReplacementAttempt = $mediaReplacementAttemptChanged->mediaReplacementAttempt->fresh();

        if (! $mediaReplacementAttempt instanceof MediaReplacementAttempt
            || ! in_array($mediaReplacementAttempt->status, [
                MediaReplacementStatus::Verified,
                MediaReplacementStatus::Failed,
                MediaReplacementStatus::NeedsAttention,
            ], true)) {
            return;
        }

        $actionRequest = ActionRequest::query()->find($mediaReplacementAttempt->action_request_id);
        $subtitleCaseId = $actionRequest?->payload['subtitle_case_id'] ?? null;

        if (! $actionRequest instanceof ActionRequest
            || $actionRequest->type !== 'replace_media_file'
            || ! is_int($subtitleCaseId)
            || $subtitleCaseId < 1) {
            return;
        }

        $subtitleCase = SubtitleCase::query()->find($subtitleCaseId);

        // A replacement attempt may recover: MediaReplacementTracker deliberately
        // lets download_timeout, manual_interaction_required and deletion_failed go
        // from NeedsAttention to Verified when a late import lands. The first
        // NeedsAttention already parked the case, so a later Verified for the same
        // request has to be able to resolve it — an explicitly closed case
        // (dismissed, handled) still stays closed.
        $acceptedStatuses = $mediaReplacementAttempt->status === MediaReplacementStatus::Verified
            ? [SubtitleCaseStatus::ReplacementRequested, SubtitleCaseStatus::NeedsReview]
            : [SubtitleCaseStatus::ReplacementRequested];

        if (! $subtitleCase instanceof SubtitleCase
            || ! in_array($subtitleCase->status, $acceptedStatuses, true)
            || $subtitleCase->replacement_action_request_id !== $actionRequest->id) {
            return;
        }

        match ($mediaReplacementAttempt->status) {
            MediaReplacementStatus::Verified => $this->subtitleCaseLifecycle->resolve($subtitleCase),
            MediaReplacementStatus::Failed => $this->subtitleCaseLifecycle->needsReview(
                $subtitleCase,
                'replacement_failed',
            ),
            MediaReplacementStatus::NeedsAttention => $this->subtitleCaseLifecycle->needsReview(
                $subtitleCase,
                'replacement_needs_attention',
            ),
            default => null,
        };
    }
}
