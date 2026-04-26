<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Events\ServiceHealthChanged;
use App\Models\ServiceConnection;
use App\Services\ServiceClientFactory;
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
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public ServiceConnection $serviceConnection) {}

    public function handle(): void
    {
        $this->serviceConnection->refresh();
        $previousStatus = $this->serviceConnection->health_status ?? HealthStatus::Unknown;

        try {
            $result = $this->ping();
            $this->serviceConnection->forceFill([
                'health_status' => HealthStatus::Healthy,
                'health_message' => null,
                'version' => $result['version'] ?? $result['Version'] ?? $this->serviceConnection->version,
                'last_seen_at' => now(),
            ])->saveQuietly();
            $newStatus = HealthStatus::Healthy;
        } catch (Throwable $throwable) {
            $this->serviceConnection->forceFill([
                'health_status' => HealthStatus::Unhealthy,
                'health_message' => $this->formatFailureReason($throwable),
            ])->saveQuietly();
            $newStatus = HealthStatus::Unhealthy;
        }

        if ($previousStatus !== $newStatus) {
            event(new ServiceHealthChanged($this->serviceConnection->fresh(), $newStatus->value));
        }
    }

    private function formatFailureReason(Throwable $throwable): string
    {
        if ($throwable instanceof RequestException) {
            $body = trim((string) $throwable->response->body());
            $snippet = Str::of($body)
                ->replaceMatches('/\s+/', ' ')
                ->limit(160, '…')
                ->toString();

            return Str::limit(
                sprintf('HTTP %d%s', $throwable->response->status(), $snippet === '' ? '' : ': '.$snippet),
                255,
            );
        }

        if ($throwable instanceof ConnectionException) {
            return Str::limit('Connection failed: '.$throwable->getMessage(), 255);
        }

        return Str::limit(class_basename($throwable).': '.$throwable->getMessage(), 255);
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
            default => $client->getSystemStatus(),
        };
    }
}
