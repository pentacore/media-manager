<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\SubtitleAuditBatch;
use App\Settings\MediaReplacementSettings;

/**
 * Settles audit batches whose release searches never finished, for example
 * because a queue worker died. Episodes still searching past the deadline plus
 * a grace period are failed, so the batch becomes ready and admins are notified.
 * The grace is MediaReplacementSettings::subtitleAuditSweepGraceMinutes(), never
 * below the 5 minutes that keep a live search from looking expired.
 */
class SubtitleAuditBatchSweeper
{
    public function __construct(
        private readonly SubtitleAuditBatchSettler $subtitleAuditBatchSettler,
        private readonly MediaReplacementSettings $mediaReplacementSettings,
    ) {}

    /**
     * @return int The number of batches settled.
     */
    public function sweep(): int
    {
        $expired = SubtitleAuditBatch::query()
            ->where('status', SubtitleAuditBatchStatus::Searching)
            ->where('search_deadline_at', '<', now()->subMinutes($this->mediaReplacementSettings->subtitleAuditSweepGraceMinutes()))
            ->get();

        foreach ($expired as $subtitleAuditBatch) {
            $subtitleAuditBatch->episodes()
                ->where('outcome', SubtitleAuditEpisodeOutcome::Searching)
                ->update([
                    'outcome' => SubtitleAuditEpisodeOutcome::Failed,
                    'failure_reason' => 'search_expired',
                ]);

            $this->subtitleAuditBatchSettler->settle($subtitleAuditBatch);
            // A batch nobody handed off or showed (chat turn and watchdog both lost)
            // would otherwise be ready without a notification and unclaimable.
            $this->subtitleAuditBatchSettler->adopt($subtitleAuditBatch);
        }

        return $expired->count();
    }
}
