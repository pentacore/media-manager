<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerFilesystemSample>
 */
class ServerFilesystemSampleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'resolution' => ServerMetricSample::MINUTE,
            'recorded_at' => now()->startOfMinute(),
            'mount' => '/',
            'fstype' => 'ext4',
            'total_bytes' => 107_374_182_400,
            'used_bytes' => 32_212_254_720,
            'available_bytes' => 75_161_927_680,
        ];
    }

    public function at(CarbonImmutable $recordedAt): static
    {
        return $this->state(fn (array $attributes): array => ['recorded_at' => $recordedAt]);
    }

    public function resolution(int $resolution): static
    {
        return $this->state(fn (array $attributes): array => ['resolution' => $resolution]);
    }

    public function mount(string $mount): static
    {
        return $this->state(fn (array $attributes): array => ['mount' => $mount]);
    }

    public function bytes(int $total, int $used, int $available): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'available_bytes' => $available,
        ]);
    }
}
