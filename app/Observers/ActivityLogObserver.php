<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\ActivityLogCreated;
use App\Models\ActivityLog;

class ActivityLogObserver
{
    public function created(ActivityLog $activityLog): void
    {
        event(new ActivityLogCreated($activityLog));
    }
}
