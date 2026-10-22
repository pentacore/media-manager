<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Enums\QueueLane;
use App\Settings\MonitoringSettings;
use App\Support\OpsHeartbeat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pings the outside Uptime Kuma push monitor. The monitor alerts when the
 * pings stop (dead scheduler or platform) or say `down` (the webhooks queue
 * lane, where agent reports are processed, has no fresh heartbeat). A failed
 * push is logged (never with the URL, its path carries the token) and never
 * thrown: a flaky watcher must not break the scheduler. A `down` push that
 * reached the monitor is a successful push.
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

        [$status, $message] = $this->health();

        try {
            $response = Http::timeout((int) config('mediamanager.monitoring.uptime_kuma_timeout_seconds'))
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->withUserAgent('MediaManager/'.config('app.version').' '.class_basename($this))
                ->get(strtok($url, '?'), ['status' => $status, 'msg' => $message]);
        } catch (Throwable) {
            // The exception chain carries the URL (and its token): log nothing from it.
            Log::warning('UptimeKumaPusher: push failed', ['status' => 'failed']);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('UptimeKumaPusher: push failed', ['status' => $response->status()]);

            return false;
        }

        return true;
    }

    /**
     * Agent reports are processed on the webhooks lane, so a dead worker there
     * stops host monitoring while the app itself looks fine.
     *
     * @return array{0: string, 1: string}
     */
    private function health(): array
    {
        $component = OpsHeartbeat::forLane(QueueLane::Webhooks);
        $stale = OpsHeartbeat::stale([$component], (int) config('mediamanager.monitoring.platform_gap_seconds'));

        if (! array_key_exists($component, $stale)) {
            return ['up', 'OK'];
        }

        return ['down', $stale[$component] === null
            ? 'no queue heartbeat'
            : sprintf('%s stale (%ds)', $component, $stale[$component])];
    }
}
