<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HostCheckStatus;
use App\Enums\HostCheckType;
use App\Models\Server;
use App\Models\ServerCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerCheck>
 */
class ServerCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => HostCheckType::MountPresent,
            'params' => ['path' => '/mnt/backups'],
            'is_enabled' => true,
        ];
    }

    public function mountPresent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => HostCheckType::MountPresent,
            'params' => ['path' => '/mnt/backups', 'fstype' => 'nfs4', 'source' => 'nas:/backups'],
        ]);
    }

    public function pathOnMount(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => HostCheckType::PathOnMount,
            'params' => ['path' => '/mnt/backups/daily', 'mount' => '/mnt/backups'],
        ]);
    }

    public function fileFreshness(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => HostCheckType::FileFreshness,
            'params' => ['path' => '/mnt/backups/daily', 'pattern' => '*.tar.gz', 'warn_hours' => 26, 'crit_hours' => 48],
        ]);
    }

    public function dbPing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => HostCheckType::DbPing,
            'params' => ['engine' => 'postgres', 'host' => '127.0.0.1', 'port' => 5432],
        ]);
    }

    public function mountpointProtected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => HostCheckType::MountpointProtected,
            'params' => ['path' => '/mnt/backups'],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => ['is_enabled' => false]);
    }

    public function withResult(HostCheckStatus $status, ?string $message = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_status' => $status,
            'last_message' => $message,
            'last_evaluated_at' => now(),
        ]);
    }
}
