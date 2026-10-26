<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SubtitleAuditBatchStatus;
use App\Models\SubtitleAuditBatch;
use Illuminate\Support\Sleep;

final class SubtitleAuditBatchWaiter
{
    /**
     * Poll once per second until the batch is ready (true) or the time is up (false).
     */
    public function waitForReady(SubtitleAuditBatch $subtitleAuditBatch, int $timeoutSeconds): bool
    {
        for ($elapsed = 0; $elapsed < $timeoutSeconds; $elapsed++) {
            if ($this->isReady($subtitleAuditBatch)) {
                return true;
            }

            Sleep::for(1)->second();
        }

        return $this->isReady($subtitleAuditBatch);
    }

    private function isReady(SubtitleAuditBatch $subtitleAuditBatch): bool
    {
        return SubtitleAuditBatch::query()
            ->whereKey($subtitleAuditBatch->id)
            ->where('status', SubtitleAuditBatchStatus::Ready)
            ->exists();
    }
}
