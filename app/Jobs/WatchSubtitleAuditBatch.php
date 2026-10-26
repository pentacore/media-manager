<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SubtitleAuditBatch;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * The watchdog of a subtitle audit batch. The chat turn that started the batch
 * runs inside the HTTP request, so the request can die (the Octane ceiling, a
 * crash) before the tool showed the card or handed the batch off. Dispatched
 * delayed past any request that could still be running, it adopts a batch
 * neither path claimed, so admins are still notified. Instant, so it stays on
 * the default lane.
 */
#[Timeout(30)]
#[Tries(3)]
#[Backoff(10)]
final class WatchSubtitleAuditBatch implements ShouldQueue
{
    use Queueable;

    /** Longer than the Octane request ceiling (150 s), so the chat turn is over. */
    public const int DELAY_SECONDS = 180;

    public function __construct(public readonly int $subtitleAuditBatchId) {}

    public function handle(SubtitleAuditBatchSettler $subtitleAuditBatchSettler): void
    {
        $subtitleAuditBatch = SubtitleAuditBatch::query()->find($this->subtitleAuditBatchId);

        if (! $subtitleAuditBatch instanceof SubtitleAuditBatch) {
            return;
        }

        $subtitleAuditBatchSettler->adopt($subtitleAuditBatch);
    }
}
