<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\AiUsage\PoolHeadroom;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A pool's caps changed: tier selection must not use cached headroom.
 */
class AiFreeUsagePoolObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(): void
    {
        resolve(PoolHeadroom::class)->flush();
    }

    public function deleted(): void
    {
        resolve(PoolHeadroom::class)->flush();
    }
}
