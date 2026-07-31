<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ReconcileSubtitleCase;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
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
            || ! in_array($actionRequest->status, [
                ActionRequestStatus::Failed,
                ActionRequestStatus::Rejected,
                ActionRequestStatus::Completed,
            ], true)) {
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
            if ($actionRequest->status !== ActionRequestStatus::Completed) {
                $this->markReplacementFailure($subtitleCase, $actionRequest);
            }

            return;
        }

        if (! in_array($actionRequest->type, self::BAZARR_DOWNLOAD_TYPES, true)) {
            return;
        }

        if ($actionRequest->status === ActionRequestStatus::Completed) {
            $this->reconcileCompletedDownload($subtitleCase, $actionRequest);

            return;
        }

        $this->markDownloadFailure($subtitleCase, $actionRequest);
    }

    /**
     * A completed download is only a hint that the track may now exist, so the
     * case keeps waiting for evidence — but something has to look. The bulk
     * sweep cannot: it only checks the download outcome for `forCase()` jobs, a
     * resolved target drops out of the candidate feed, and the default Apprise
     * notification carries no media ids to target with. Without this, completed
     * downloads sit in download_requested forever.
     */
    private function reconcileCompletedDownload(
        SubtitleCase $subtitleCase,
        ActionRequest $actionRequest,
    ): void {
        if ($subtitleCase->status !== SubtitleCaseStatus::DownloadRequested
            || ! $this->correlates($subtitleCase, $actionRequest)) {
            return;
        }

        dispatch(ReconcileSubtitleCase::forCase($subtitleCase)->delay(now()->addMinute()));
    }

    private function markDownloadFailure(
        SubtitleCase $subtitleCase,
        ActionRequest $actionRequest,
    ): void {
        if ($subtitleCase->status !== SubtitleCaseStatus::DownloadRequested
            || ! $this->correlates($subtitleCase, $actionRequest)) {
            return;
        }

        $language = $actionRequest->payload['language'] ?? null;
        $language = is_string($language) && $language !== '' ? mb_substr($language, 0, 20) : null;

        if (($actionRequest->result['indeterminate'] ?? false) === true) {
            $this->recordIndeterminateDownload($subtitleCase, $actionRequest, $language);

            return;
        }

        $this->subtitleCaseLifecycle->needsReview(
            $subtitleCase,
            $language === null
                ? 'bazarr_download_failed'
                : 'bazarr_download_failed:'.$language,
        );
    }

    /**
     * An uncertain write may still have landed in Bazarr, and only a live read can
     * tell. BazarrActions already scheduled targeted reconciliation, which verifies
     * download_requested and bazarr_searching cases only — parking the case here
     * would turn that read into a no-op and leave a satisfied requirement stranded
     * outside the missing feed forever. The case keeps waiting and the uncertainty
     * is recorded as its own attempt instead.
     */
    private function recordIndeterminateDownload(
        SubtitleCase $subtitleCase,
        ActionRequest $actionRequest,
        ?string $language,
    ): void {
        SubtitleCaseAttempt::query()->firstOrCreate(
            [
                'subtitle_case_id' => $subtitleCase->id,
                'action_request_id' => $actionRequest->id,
                'type' => SubtitleCaseAttemptType::Download,
                'outcome' => SubtitleCaseAttemptOutcome::Indeterminate,
            ],
            [
                'summary' => array_filter([
                    'result' => 'bazarr_download_indeterminate',
                    'language' => $language,
                ], static fn (mixed $value): bool => $value !== null),
                'error_category' => 'needs_reconciliation',
                'started_at' => now(),
                'completed_at' => now(),
            ],
        );
    }

    /**
     * A probe queues one request per missing language and the scalar column only
     * keeps the most recent, so correlation has to consider every per-language
     * request recorded in the evidence map. Otherwise a failure for any language
     * but the last leaves its requirement unmet with the case still marked
     * download_requested.
     */
    private function correlates(SubtitleCase $subtitleCase, ActionRequest $actionRequest): bool
    {
        if ($subtitleCase->download_action_request_id === $actionRequest->id) {
            return true;
        }

        $downloadRequests = $subtitleCase->evidence['download_requests'] ?? null;

        return is_array($downloadRequests)
            && in_array($actionRequest->id, array_map(intval(...), array_values($downloadRequests)), true);
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
