<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Support\OpsHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Proves a lane end to end: the scheduler queues one per lane every minute
 * and a worker draining that lane records it. Unique per lane, so a stalled
 * lane holds one pending heartbeat instead of piling them up.
 */
#[UniqueFor(600)]
final class RecordQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public QueueLane $queueLane)
    {
        // Parameterised by lane, so the lane is set here, not with #[Queue].
        $this->onQueue($queueLane->value);
    }

    public function uniqueId(): string
    {
        return $this->queueLane->value;
    }

    public function handle(): void
    {
        OpsHeartbeat::record(OpsHeartbeat::forLane($this->queueLane));
    }
}
