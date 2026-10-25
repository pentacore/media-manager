<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Models\SubtitleAuditBatch;

/**
 * Not final: the searcher and job tests mock it to assert a batch is settled.
 */
class SubtitleAuditBatchSettler
{
    /** Stub: Task 5 fills in settling a batch once its last episode has an outcome. */
    public function settle(SubtitleAuditBatch $subtitleAuditBatch): void {}
}
