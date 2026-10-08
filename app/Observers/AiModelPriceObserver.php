<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\AiUsage\PoolHeadroom;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A model's pool link can change on its price row: tier selection must not use cached headroom.
 */
class AiModelPriceObserver implements ShouldHandleEventsAfterCommit
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
