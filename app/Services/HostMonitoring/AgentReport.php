<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Enums\HostCheckStatus;
use Carbon\CarbonImmutable;

/**
 * One agent report as validated by AgentReportRequest, filed under the time the platform received it (the agent's `sent_at` is never trusted for placement).
 */
final readonly class AgentReport
{
    /**
     * @param  array{hostname: string, os: ?string, kernel: ?string, arch: ?string, uptime_seconds: ?int}  $host
     * @param  list<float>  $load
     * @param  list<array{mount: string, device: ?string, fstype: ?string, unresponsive: bool, total_bytes: ?int, used_bytes: ?int, available_bytes: ?int}>  $filesystems  byte fields are null exactly when `unresponsive` is true
     * @param  list<string>|null  $failedUnits  null when the host has no systemd
     * @param  list<array{name: string, state: string, health: ?string, restart_count: int}>|null  $containers  null when Docker is absent or did not answer
     * @param  list<array{id: string, type: string, status: HostCheckStatus, message: ?string, observed: ?array<array-key, mixed>}>  $checks
     */
    public function __construct(
        public CarbonImmutable $receivedAt,
        public string $agentVersion,
        public int $configVersion,
        public array $host,
        public float $cpuPercent,
        public array $load,
        public float $load1,
        public int $memoryTotalBytes,
        public int $memoryAvailableBytes,
        public int $memoryUsedBytes,
        public int $swapTotalBytes,
        public int $swapFreeBytes,
        public int $swapUsedBytes,
        public int $oomKillsTotal,
        public array $filesystems,
        public ?array $failedUnits,
        public ?array $containers,
        public array $checks,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  AgentReportRequest::validated()
     */
    public static function fromPayload(array $payload, CarbonImmutable $receivedAt): self
    {
        /** @var array{hostname: string, os?: ?string, kernel?: ?string, arch?: ?string, uptime_seconds?: ?int} $host */
        $host = $payload['host'];
        /** @var array{cpu_percent: int|float, load: list<int|float>, memory: array{total_bytes: int, available_bytes: int, swap_total_bytes: int, swap_free_bytes: int}, oom_kills_total: int, filesystems?: list<array<string, mixed>>, failed_units?: ?list<string>, containers?: ?list<array<string, mixed>>} $metrics */
        $metrics = $payload['metrics'];
        $memory = $metrics['memory'];
        $load = array_map(floatval(...), $metrics['load']);

        return new self(
            receivedAt: $receivedAt,
            agentVersion: (string) $payload['agent_version'],
            configVersion: (int) $payload['config_version'],
            host: [
                'hostname' => $host['hostname'],
                'os' => $host['os'] ?? null,
                'kernel' => $host['kernel'] ?? null,
                'arch' => $host['arch'] ?? null,
                'uptime_seconds' => isset($host['uptime_seconds']) ? (int) $host['uptime_seconds'] : null,
            ],
            cpuPercent: (float) $metrics['cpu_percent'],
            load: $load,
            load1: $load[0] ?? 0.0,
            memoryTotalBytes: (int) $memory['total_bytes'],
            memoryAvailableBytes: (int) $memory['available_bytes'],
            memoryUsedBytes: max(0, (int) $memory['total_bytes'] - (int) $memory['available_bytes']),
            swapTotalBytes: (int) $memory['swap_total_bytes'],
            swapFreeBytes: (int) $memory['swap_free_bytes'],
            swapUsedBytes: max(0, (int) $memory['swap_total_bytes'] - (int) $memory['swap_free_bytes']),
            oomKillsTotal: (int) $metrics['oom_kills_total'],
            filesystems: array_map(self::filesystem(...), $metrics['filesystems'] ?? []),
            failedUnits: isset($metrics['failed_units']) ? array_map(strval(...), $metrics['failed_units']) : null,
            containers: isset($metrics['containers']) ? array_map(self::container(...), $metrics['containers']) : null,
            checks: array_map(self::check(...), array_values($payload['checks'] ?? [])),
        );
    }

    /**
     * The receive time's minute, which every 60 s sample of this report is filed under.
     */
    public function minute(): CarbonImmutable
    {
        return $this->receivedAt->startOfMinute();
    }

    /**
     * The latest state kept on `servers.last_report` for the admin pages.
     *
     * @return array{received_at: string, uptime_seconds: ?int, cpu_percent: float, load: list<float>, memory: array{total_bytes: int, available_bytes: int, swap_total_bytes: int, swap_free_bytes: int}, oom_kills_total: int, filesystems: list<array{mount: string, device: ?string, fstype: ?string, unresponsive: bool, total_bytes: ?int, used_bytes: ?int, available_bytes: ?int}>, failed_units: ?list<string>, containers: ?list<array{name: string, state: string, health: ?string, restart_count: int}>}
     */
    public function snapshot(): array
    {
        return [
            'received_at' => $this->receivedAt->toIso8601String(),
            'uptime_seconds' => $this->host['uptime_seconds'],
            'cpu_percent' => $this->cpuPercent,
            'load' => $this->load,
            'memory' => [
                'total_bytes' => $this->memoryTotalBytes,
                'available_bytes' => $this->memoryAvailableBytes,
                'swap_total_bytes' => $this->swapTotalBytes,
                'swap_free_bytes' => $this->swapFreeBytes,
            ],
            'oom_kills_total' => $this->oomKillsTotal,
            'filesystems' => $this->filesystems,
            'failed_units' => $this->failedUnits,
            'containers' => $this->containers,
        ];
    }

    /**
     * An entry without the flag comes from an agent that predates it and counts as responsive.
     *
     * @param  array<string, mixed>  $filesystem
     * @return array{mount: string, device: ?string, fstype: ?string, unresponsive: bool, total_bytes: ?int, used_bytes: ?int, available_bytes: ?int}
     */
    private static function filesystem(array $filesystem): array
    {
        $unresponsive = (bool) ($filesystem['unresponsive'] ?? false);
        $bytes = static fn (string $key): ?int => $unresponsive ? null : (int) $filesystem[$key];

        return [
            'mount' => (string) $filesystem['mount'],
            'device' => isset($filesystem['device']) ? (string) $filesystem['device'] : null,
            'fstype' => isset($filesystem['fstype']) ? (string) $filesystem['fstype'] : null,
            'unresponsive' => $unresponsive,
            'total_bytes' => $bytes('total_bytes'),
            'used_bytes' => $bytes('used_bytes'),
            'available_bytes' => $bytes('available_bytes'),
        ];
    }

    /**
     * @param  array<string, mixed>  $container
     * @return array{name: string, state: string, health: ?string, restart_count: int}
     */
    private static function container(array $container): array
    {
        return [
            'name' => (string) $container['name'],
            'state' => (string) $container['state'],
            'health' => isset($container['health']) ? (string) $container['health'] : null,
            'restart_count' => (int) $container['restart_count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array{id: string, type: string, status: HostCheckStatus, message: ?string, observed: ?array<array-key, mixed>}
     */
    private static function check(array $check): array
    {
        return [
            'id' => (string) $check['id'],
            'type' => (string) $check['type'],
            'status' => HostCheckStatus::from((string) $check['status']),
            'message' => isset($check['message']) ? (string) $check['message'] : null,
            'observed' => isset($check['observed']) && is_array($check['observed']) ? $check['observed'] : null,
        ];
    }
}
