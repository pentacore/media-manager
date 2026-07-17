<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseStatus;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleCaseLifecycle;

final readonly class UpdateSubtitleCaseFromActionRequest
{
    private const array BAZARR_DOWNLOAD_TYPES = [
        'bazarr_download_best',
        'bazarr_download_exact',
    ];

    public function __construct(
        private SubtitleCaseLifecycle $subtitleCaseLifecycle,
    ) {}

    public function handle(ActionRequestStatusChanged $actionRequestStatusChanged): void
    {
        $actionRequest = $actionRequestStatusChanged->actionRequest->fresh();

        if (! $actionRequest instanceof ActionRequest
            || ! in_array($actionRequest->status, [ActionRequestStatus::Failed, ActionRequestStatus::Rejected], true)) {
            return;
        }

        $subtitleCaseId = $actionRequest->payload['subtitle_case_id'] ?? null;

        if (! is_int($subtitleCaseId) || $subtitleCaseId < 1) {
            return;
        }

        $subtitleCase = SubtitleCase::query()->find($subtitleCaseId);

        if (! $subtitleCase instanceof SubtitleCase) {
            return;
        }

        if ($actionRequest->type === 'replace_media_file') {
            $this->markReplacementFailure($subtitleCase, $actionRequest);

            return;
        }

        if (in_array($actionRequest->type, self::BAZARR_DOWNLOAD_TYPES, true)) {
            $this->markDownloadFailure($subtitleCase, $actionRequest);
        }
    }

    private function markDownloadFailure(
        SubtitleCase $subtitleCase,
        ActionRequest $actionRequest,
    ): void {
        if ($subtitleCase->status !== SubtitleCaseStatus::DownloadRequested
            || $subtitleCase->download_action_request_id !== $actionRequest->id) {
            return;
        }

        $reason = ($actionRequest->result['indeterminate'] ?? false) === true
            ? 'bazarr_download_indeterminate'
            : 'bazarr_download_failed';

        $this->subtitleCaseLifecycle->needsReview($subtitleCase, $reason);
    }

    private function markReplacementFailure(
        SubtitleCase $subtitleCase,
        ActionRequest $actionRequest,
    ): void {
        if ($subtitleCase->status !== SubtitleCaseStatus::ReplacementRequested
            || $subtitleCase->replacement_action_request_id !== $actionRequest->id) {
            return;
        }

        $this->subtitleCaseLifecycle->needsReview($subtitleCase, 'replacement_action_failed');
    }
}
