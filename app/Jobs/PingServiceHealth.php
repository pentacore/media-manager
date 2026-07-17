<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Events\ServiceHealthChanged;
use App\Models\ServiceConnection;
use App\Models\ServiceMetric;
use App\Services\ServiceClientFactory;
use App\Support\UrlQueryRedactor;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class PingServiceHealth implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
     * The result is persisted, broadcast to every member, and echoed by the
     * scheduler — exception messages embed the full request URI, whose query
     * string can carry credentials (SABnzbd's mandatory `apikey`), so every
     * branch is redacted before leaving this method.
     */
    private function formatFailureReason(Throwable $throwable): string
    {
        if ($throwable instanceof RequestException) {
            $body = trim((string) $throwable->response->body());
            $snippet = Str::of($body)
                ->replaceMatches('/\s+/', ' ')
                ->limit(160, '…')
                ->toString();

            return Str::limit(
                UrlQueryRedactor::redact(
                    sprintf('HTTP %d%s', $throwable->response->status(), $snippet === '' ? '' : ': '.$snippet),
                ),
                255,
            );
        }

        if ($throwable instanceof ConnectionException) {
            return Str::limit(UrlQueryRedactor::redact('Connection failed: '.$throwable->getMessage()), 255);
        }

        return Str::limit(UrlQueryRedactor::redact(class_basename($throwable).': '.$throwable->getMessage()), 255);
    }

    /**
     * @return array<string, mixed>
     */
    private function ping(): array
    {
        $client = resolve(ServiceClientFactory::class)->make($this->serviceConnection);

        return match ($this->serviceConnection->type) {
            ServiceType::Emby => $client->getSystemInfo(),
            ServiceType::Seerr => $client->getStatus(),
            ServiceType::SABnzbd => $client->getVersion(),
            default => $client->getSystemStatus(),
        };
    }
}
