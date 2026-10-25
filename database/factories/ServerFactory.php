<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /** A valid enrollment token (32 alphanumeric characters) for tests that enroll a pendingEnrollment() server. */
    public const string ENROLLMENT_TOKEN = 'EnrollTokenAAAAAAAAAAAAAAAAAAAAA';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::slug(fake()->unique()->words(2, true)),
        ];
    }

    public function pendingEnrollment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enrollment_token_hash' => hash('sha256', self::ENROLLMENT_TOKEN),
            'enrollment_expires_at' => now()->addHours(24),
        ]);
    }

    public function enrolled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'hostname' => fake()->domainWord(),
            'os' => 'Debian GNU/Linux 12 (bookworm)',
            'kernel' => '6.1.0-18-amd64',
            'arch' => 'amd64',
            'agent_version' => '0.1.0',
            'token_hash' => hash('sha256', 'secret'),
            'enrollment_token_hash' => null,
            'enrollment_expires_at' => null,
            'enrolled_at' => now(),
        ]);
    }

    public function reporting(): static
    {
        return $this->enrolled()->state(fn (array $attributes): array => [
            'last_report_at' => now(),
            'last_report' => [
                'received_at' => now()->toIso8601String(),
                'uptime_seconds' => 86_400,
                'cpu_percent' => 12.5,
                'load' => [0.3, 0.2, 0.1],
                'memory' => [
                    'total_bytes' => 8_589_934_592,
                    'available_bytes' => 6_442_450_944,
                    'swap_total_bytes' => 2_147_483_648,
                    'swap_free_bytes' => 2_147_483_648,
                ],
                'oom_kills_total' => 0,
                'filesystems' => [[
                    'mount' => '/',
                    'device' => '/dev/sda1',
                    'fstype' => 'ext4',
                    'unresponsive' => false,
                    'total_bytes' => 107_374_182_400,
                    'used_bytes' => 32_212_254_720,
                    'available_bytes' => 75_161_927_680,
                ]],
                'failed_units' => [],
                'containers' => [],
            ],
        ]);
    }

    public function agentVersion(string $version): static
    {
        return $this->state(fn (array $attributes): array => ['agent_version' => $version]);
    }
}
