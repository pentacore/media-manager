<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\ServiceConnectionDeleted;
use App\Events\ServiceConnectionUpserted;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ServiceConnectionObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Queue a health ping + version fetch for every newly created connection,
     * and broadcast the new row to admin clients.
     */
    public function created(ServiceConnection $serviceConnection): void
    {
        event(new ServiceConnectionUpserted($serviceConnection));

        if (! $serviceConnection->is_active) {
            return;
        }

        dispatch(new PingServiceHealth($serviceConnection));
        dispatch(new FetchLatestServiceVersion($serviceConnection));
    }

    /**
     * Re-check when the connection's identity or auth changed (type/url/api_key)
     * or when it was re-activated, and broadcast the latest snapshot.
     *
     * The jobs use saveQuietly() internally so they don't re-trigger this observer.
     */
    public function updated(ServiceConnection $serviceConnection): void
    {
        event(new ServiceConnectionUpserted($serviceConnection));

        $identityChanged = $serviceConnection->wasChanged(['type', 'url', 'api_key']);
        $reactivated = $serviceConnection->wasChanged('is_active') && $serviceConnection->is_active;

        if (! $identityChanged && ! $reactivated) {
            return;
        }

        if (! $serviceConnection->is_active) {
            return;
        }

        dispatch(new PingServiceHealth($serviceConnection));
        dispatch(new FetchLatestServiceVersion($serviceConnection));
    }

    public function deleted(ServiceConnection $serviceConnection): void
    {
        event(new ServiceConnectionDeleted($serviceConnection->id));
    }
}
