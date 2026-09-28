<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QueueLane;
use App\Jobs\RecordQueueHeartbeat;
use App\Support\OpsHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Record the scheduler heartbeat and queue one heartbeat job per queue lane.')]
#[Signature('ops:record-heartbeat')]
class RecordOpsHeartbeat extends Command
{
    public function handle(): int
    {
        OpsHeartbeat::record(OpsHeartbeat::SCHEDULER);

        foreach (QueueLane::cases() as $queueLane) {
            dispatch(new RecordQueueHeartbeat($queueLane));
        }

        return self::SUCCESS;
    }
}
