<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceConnectionSeeder extends Seeder
{
    /**
     * @var array<int, array{prefix: string, type: ServiceType, defaultName: string}>
     */
    private const array SERVICES = [
        ['prefix' => 'SONARR', 'type' => ServiceType::Sonarr, 'defaultName' => 'Sonarr'],
        ['prefix' => 'RADARR', 'type' => ServiceType::Radarr, 'defaultName' => 'Radarr'],
        ['prefix' => 'EMBY', 'type' => ServiceType::Emby, 'defaultName' => 'Emby'],
        ['prefix' => 'SEERR', 'type' => ServiceType::Seerr, 'defaultName' => 'Seerr'],
        ['prefix' => 'PROWLARR', 'type' => ServiceType::Prowlarr, 'defaultName' => 'Prowlarr'],
    ];

    public function run(): void
    {
        foreach (self::SERVICES as $service) {
            $this->seedService($service['prefix'], $service['type'], $service['defaultName']);
        }
    }

    private function seedService(string $prefix, ServiceType $serviceType, string $defaultName): void
    {
        $url = $this->readEnv(sprintf('%s_URL', $prefix));
        $apiKey = $this->readEnv(sprintf('%s_API_KEY', $prefix));

        if ($url === null || $url === '' || $apiKey === null || $apiKey === '') {
            $this->info(sprintf('Skipped %s — %s_URL / %s_API_KEY not set.', $defaultName, $prefix, $prefix));

            return;
        }

        $nameEnv = $this->readEnv(sprintf('%s_NAME', $prefix));
        $name = $nameEnv !== null && $nameEnv !== '' ? $nameEnv : $defaultName;
        $webhookEnv = $this->readEnv(sprintf('%s_WEBHOOK_TOKEN', $prefix));
        $webhookToken = $webhookEnv !== null && $webhookEnv !== '' ? $webhookEnv : Str::random(40);

        // Env vars are authoritative: if a connection of this type already exists,
        // update it to match the env (so stale factory data doesn't beat the .env).
        $existing = ServiceConnection::where('type', $serviceType)->first();

        if ($existing !== null) {
            if ($existing->url === $url && $existing->api_key === $apiKey) {
                $this->info(sprintf('Skipped %s — already matches env.', $defaultName));

                return;
            }

            ServiceConnection::withoutEvents(function () use ($existing, $name, $url, $apiKey, $webhookToken): void {
                $existing->update([
                    'name' => $name,
                    'url' => $url,
                    'api_key' => $apiKey,
                    'webhook_token' => $webhookToken,
                    'is_active' => true,
                ]);
            });

            $this->info(sprintf('Updated %s from env.', $name));

            return;
        }

        ServiceConnection::withoutEvents(function () use ($serviceType, $name, $url, $apiKey, $webhookToken): void {
            ServiceConnection::create([
                'type' => $serviceType,
                'name' => $name,
                'url' => $url,
                'api_key' => $apiKey,
                'webhook_token' => $webhookToken,
                'is_active' => true,
                'settings' => null,
            ]);
        });

        $this->info(sprintf('Seeded %s from env.', $name));
    }

    private function info(string $message): void
    {
        if ($this->command !== null) {
            $this->command->info($message);
        }
    }

    /**
     * Read a process-environment variable. Uses getenv() rather than env() so
     * test-time putenv() values propagate (Laravel's Env helper ignores
     * putenv by default).
     */
    private function readEnv(string $key): ?string
    {
        $value = getenv($key);

        if ($value === false) {
            return null;
        }

        return $value;
    }
}
