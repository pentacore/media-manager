<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Events\ServiceHealthChanged;
use App\Models\ServiceConnection;
use App\Models\ServiceMetric;
use App\Services\Arr\ArrUnexpectedResponse;
use App\Services\Emby\EmbyUnexpectedResponse;
use App\Services\Sabnzbd\SabnzbdRefused;
use App\Services\ServiceClientFactory;
use App\Support\UpstreamErrorText;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class PingServiceHealth implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /**
     * Deleting the underlying model while this job is queued must drop the
     * job silently instead of filling failed_jobs with
     * ModelNotFoundException noise.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public ServiceConnection $serviceConnection) {}

    public function handle(): void
    {
        $this->serviceConnection->refresh();

        $startedAt = microtime(true);
        $latencyMs = null;
        $message = null;

        try {
            $result = $this->ping();
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->serviceConnection->forceFill([
                'health_status' => HealthStatus::Healthy,
                'health_message' => null,
                'version' => $result['version'] ?? $result['Version'] ?? $this->serviceConnection->version,
                'last_seen_at' => now(),
            ])->saveQuietly();
            $newStatus = HealthStatus::Healthy;
        } catch (Throwable $throwable) {
            // Connection / request errors that come back with a real HTTP
            // response still produced a measurable round-trip — keep that
            // latency. Pre-response failures (DNS, ECONNREFUSED) leave it
            // null so the strip can render them as "no data".
            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

            if ($throwable instanceof RequestException) {
                $latencyMs = $elapsed;
            }

            $message = $this->formatFailureReason($throwable);

            $this->serviceConnection->forceFill([
                'health_status' => HealthStatus::Unhealthy,
                'health_message' => $message,
            ])->saveQuietly();
            $newStatus = HealthStatus::Unhealthy;
        }

        ServiceMetric::create([
            'service_connection_id' => $this->serviceConnection->id,
            'status' => $newStatus,
            'latency_ms' => $latencyMs,
            'message' => $message,
            'recorded_at' => now(),
        ]);

        // Broadcast on any UI-relevant change, not only on a status flip — so
        // a fresh `health_message` while still Unhealthy, or a refreshed
        // `last_seen_at` heartbeat, propagates to subscribed pages.
        if ($this->serviceConnection->wasChanged(['health_status', 'health_message', 'version', 'last_seen_at'])) {
            event(new ServiceHealthChanged($this->serviceConnection->fresh(), $newStatus->value));
        }
    }

    /**
     * The result is persisted, broadcast to every manage-library user (via
     * ServiceHealthChanged) and echoed by the scheduler. An HTTP failure is
     * stored as a fixed sentence per status class: an error page can carry
     * internal hostnames or tokens the sanitizer does not recognise. The
     * app's own fixed-message refusals keep their sentence, which never
     * quotes the body. Connection and other exception messages embed the
     * request URI (SABnzbd's mandatory `apikey`) or local paths, so they
     * still go through UpstreamErrorText::sanitize().
     */
    private function formatFailureReason(Throwable $throwable): string
    {
        if ($throwable instanceof ArrUnexpectedResponse || $throwable instanceof SabnzbdRefused || $throwable instanceof EmbyUnexpectedResponse) {
            return sprintf('HTTP %d: %s', $throwable->response->status(), $throwable->getMessage());
        }

        if ($throwable instanceof RequestException) {
            return $this->httpFailureSentence($throwable->response->status());
        }

        if ($throwable instanceof ConnectionException) {
            return UpstreamErrorText::sanitize(sprintf('Connection failed: %s', $throwable->getMessage()), 255);
        }

        return UpstreamErrorText::sanitize(sprintf('%s: %s', class_basename($throwable), $throwable->getMessage()), 255);
    }

    private function httpFailureSentence(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => sprintf('HTTP %d: the service rejected the API key.', $status),
            $status >= 500 => sprintf('HTTP %d: the service reported a server error.', $status),
            $status >= 400 => sprintf('HTTP %d: the service refused the request.', $status),
            default => sprintf('HTTP %d: the service answered with something other than its API data.', $status),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function ping(): array
    {
        $client = resolve(ServiceClientFactory::class)->make($this->serviceConnection);

        return match ($this->serviceConnection->type) {
            ServiceType::Bazarr => $client->getFreshSystemStatus(),
            ServiceType::Emby => $client->getSystemInfo(),
            ServiceType::Seerr => $client->getStatus(),
            ServiceType::SABnzbd => $client->getVersion(),
            default => $client->getSystemStatus(),
        };
    }
}
