<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Events\ServiceHealthChanged;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use App\Services\Jellyseerr\JellyseerrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Console\Command;
use Throwable;

class CheckServiceHealth extends Command
{
    #[\Override]
    protected $signature = 'services:check-health';

    #[\Override]
    protected $description = 'Ping every active service connection and update health_status.';

    public function handle(): int
    {
        $connections = ServiceConnection::where('is_active', true)->get();

        foreach ($connections as $connection) {
            $previousStatus = $connection->health_status ?? HealthStatus::Unknown;

            try {
                $result = $this->pingConnection($connection);
                $connection->forceFill([
                    'health_status' => HealthStatus::Healthy,
                    'version' => $result['version'] ?? $result['Version'] ?? $connection->version,
                    'last_seen_at' => now(),
                ])->save();

                $newStatus = HealthStatus::Healthy;
            } catch (Throwable $e) {
                $connection->forceFill([
                    'health_status' => HealthStatus::Unhealthy,
                ])->save();

                $newStatus = HealthStatus::Unhealthy;

                $this->warn(sprintf('Health check failed for %s (%s): %s', $connection->name, $connection->type->value, $e->getMessage()));
            }

            if ($previousStatus !== $newStatus) {
                event(new ServiceHealthChanged($connection->fresh(), $newStatus->value));
            }
        }

        $this->info(sprintf('Checked %d service(s).', $connections->count()));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function pingConnection(ServiceConnection $serviceConnection): array
    {
        $client = match ($serviceConnection->type) {
            ServiceType::Sonarr => new SonarrClient($serviceConnection),
            ServiceType::Radarr => new RadarrClient($serviceConnection),
            ServiceType::Emby => new EmbyClient($serviceConnection),
            ServiceType::Jellyseerr => new JellyseerrClient($serviceConnection),
        };

        return match ($serviceConnection->type) {
            ServiceType::Emby => $client->getSystemInfo(),
            ServiceType::Jellyseerr => $client->getStatus(),
            default => $client->getSystemStatus(),
        };
    }
}
