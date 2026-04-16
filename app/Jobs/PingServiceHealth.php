<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Events\ServiceHealthChanged;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
                'version' => $result['version'] ?? $result['Version'] ?? $this->serviceConnection->version,
                'last_seen_at' => now(),
            ])->saveQuietly();
            $newStatus = HealthStatus::Healthy;
        } catch (Throwable) {
            $this->serviceConnection->forceFill(['health_status' => HealthStatus::Unhealthy])->saveQuietly();
            $newStatus = HealthStatus::Unhealthy;
        }

        if ($previousStatus !== $newStatus) {
            event(new ServiceHealthChanged($this->serviceConnection->fresh(), $newStatus->value));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ping(): array
    {
        $client = match ($this->serviceConnection->type) {
            ServiceType::Sonarr => new SonarrClient($this->serviceConnection),
            ServiceType::Radarr => new RadarrClient($this->serviceConnection),
            ServiceType::Emby => new EmbyClient($this->serviceConnection),
            ServiceType::Seerr => new SeerrClient($this->serviceConnection),
        };

        return match ($this->serviceConnection->type) {
            ServiceType::Emby => $client->getSystemInfo(),
            ServiceType::Seerr => $client->getStatus(),
            default => $client->getSystemStatus(),
        };
    }
}
