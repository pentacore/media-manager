<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MediaReplacement\SubtitleAuditBatchSweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Fail episodes of subtitle audit batches whose searches outlived their deadline, so the batch settles and admins are notified.')]
#[Signature('subtitles:sweep-audit-batches')]
class SweepSubtitleAuditBatches extends Command
{
    public function handle(SubtitleAuditBatchSweeper $subtitleAuditBatchSweeper): int
    {
        $settled = $subtitleAuditBatchSweeper->sweep();

        $this->info(sprintf('Settled %d expired audit batch(es).', $settled));

        return self::SUCCESS;
    }
}
