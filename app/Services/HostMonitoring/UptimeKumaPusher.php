<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Settings\MonitoringSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pings the outside Uptime Kuma push monitor. The monitor alerts when the
 * pings stop, so a dead scheduler or platform is noticed from outside. A
 * failed push is logged (never with the URL, its path carries the token)
 * and never thrown: a flaky watcher must not break the scheduler.
 */
final readonly class UptimeKumaPusher
{
    public function __construct(private MonitoringSettings $monitoringSettings) {}

    public function push(): bool
    {
        $url = $this->monitoringSettings->uptimeKumaPushUrl();

        if ($url === null) {
            return false;
        }

        try {
            $response = Http::timeout((int) config('mediamanager.monitoring.uptime_kuma_timeout_seconds'))
                ->connectTimeout(3)
                ->withUserAgent('MediaManager/'.config('app.version').' '.class_basename($this))
                ->get(strtok($url, '?'), ['status' => 'up', 'msg' => 'OK']);
        } catch (ConnectionException) {
            Log::warning('UptimeKumaPusher: push failed', ['status' => 'connection_failed']);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('UptimeKumaPusher: push failed', ['status' => $response->status()]);

            return false;
        }

        return true;
    }
}
