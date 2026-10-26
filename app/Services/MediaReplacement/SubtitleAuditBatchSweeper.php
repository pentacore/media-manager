<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\SubtitleAuditBatch;

/**
 * Settles audit batches whose release searches never finished, for example
 * because a queue worker died. Episodes still searching past the deadline plus
 * a grace period are failed, so the batch becomes ready and admins are notified.
 */
class SubtitleAuditBatchSweeper
{
    private const int GRACE_MINUTES = 5;

    public function __construct(private readonly SubtitleAuditBatchSettler $subtitleAuditBatchSettler) {}

    /**
     * @return int The number of batches settled.
     */
    public function sweep(): int
    {
        $expired = SubtitleAuditBatch::query()
            ->where('status', SubtitleAuditBatchStatus::Searching)
            ->where('search_deadline_at', '<', now()->subMinutes(self::GRACE_MINUTES))
            ->get();

        foreach ($expired as $subtitleAuditBatch) {
            $subtitleAuditBatch->episodes()
                ->where('outcome', SubtitleAuditEpisodeOutcome::Searching)
                ->update([
                    'outcome' => SubtitleAuditEpisodeOutcome::Failed,
                    'failure_reason' => 'search_expired',
                ]);

            $this->subtitleAuditBatchSettler->settle($subtitleAuditBatch);
        }

        return $expired->count();
    }
}
