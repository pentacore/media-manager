<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;

class ServiceConnectionObserver
{
    /**
     * Queue a health ping + version fetch for every newly created connection.
     */
    public function created(ServiceConnection $serviceConnection): void
    {
        if (! $serviceConnection->is_active) {
            return;
        }

        PingServiceHealth::dispatch($serviceConnection);
        FetchLatestServiceVersion::dispatch($serviceConnection);
    }

    /**
     * Re-check when the connection's identity or auth changed (type/url/api_key)
     * or when it was re-activated.
     *
     * The jobs use saveQuietly() internally so they don't re-trigger this observer.
     */
    public function updated(ServiceConnection $serviceConnection): void
    {
        $identityChanged = $serviceConnection->wasChanged(['type', 'url', 'api_key']);
        $reactivated = $serviceConnection->wasChanged('is_active') && $serviceConnection->is_active;

        if (! $identityChanged && ! $reactivated) {
            return;
        }

        if (! $serviceConnection->is_active) {
            return;
        }

        PingServiceHealth::dispatch($serviceConnection);
        FetchLatestServiceVersion::dispatch($serviceConnection);
    }
}
