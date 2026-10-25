<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\HostCheckStatus;
use App\Http\Requests\Agent\AgentReportRequest;
use App\Jobs\ProcessAgentReport;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Request fixtures shared by the agent API feature tests.
 */
final class AgentReportFixtures
{
    /** A check id the default report body carries that matches no ServerCheck. */
    public const string UNKNOWN_CHECK_ID = '01990000-0000-7000-8000-000000000001';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function agentEnrollBody(string $token, array $overrides = []): array
    {
        return [
            'enrollment_token' => $token,
            'hostname' => 'babel',
            'os' => 'Debian GNU/Linux 12 (bookworm)',
            'kernel' => '6.1.0-18-amd64',
            'arch' => 'amd64',
            'agent_version' => '0.1.0',
            ...$overrides,
        ];
    }

    /**
     * The Authorization header for a server enrolled through ServerFactory::enrolled(), whose secret is `secret`.
     *
     * @return array<string, string>
     */
    public static function agentBearer(Server $server, string $secret = 'secret'): array
    {
        return ['Authorization' => sprintf('Bearer %s.%s', $server->uuid, $secret)];
    }

    /**
     * The contract's example report. Overrides are dot-notation paths that replace the whole value there
     * (`'metrics.cpu_percent' => 20`, `'metrics.filesystems' => [...]`, `'metrics.filesystems.0.future' => 1`).
     * The default `config_version` 1 matches a fresh server's, so the default report is up to date.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function agentReportBody(array $overrides = []): array
    {
        $body = [
            'schema' => 1,
            'agent_version' => '0.1.0',
            'sent_at' => '2026-10-22T12:00:00Z',
            'config_version' => 1,
            'host' => [
                'hostname' => 'babel',
                'os' => 'unraid 7.1.4',
                'kernel' => '6.12.24-Unraid',
                'arch' => 'amd64',
                'uptime_seconds' => 86_400,
            ],
            'metrics' => [
                'cpu_percent' => 12.5,
                'load' => [0.5, 0.7, 0.9],
                'memory' => [
                    'total_bytes' => 8_589_934_592,
                    'available_bytes' => 6_442_450_944,
                    'swap_total_bytes' => 2_147_483_648,
                    'swap_free_bytes' => 1_073_741_824,
                ],
                'oom_kills_total' => 0,
                'filesystems' => [
                    self::agentFilesystem('/'),
                ],
                'failed_units' => ['backup.service'],
                'containers' => [
                    ['name' => 'postfix-mailcow', 'state' => 'running', 'health' => 'healthy', 'restart_count' => 4],
                ],
            ],
            'checks' => [
                self::agentCheckResult(self::UNKNOWN_CHECK_ID, HostCheckStatus::Ok),
            ],
        ];

        foreach ($overrides as $path => $value) {
            Arr::set($body, $path, $value);
        }

        return $body;
    }

    /**
     * One responsive filesystem entry (100 GiB, 30 GiB used), or, with `$unresponsive`, the entry an agent
     * sends for a mount whose statfs hung or failed: no byte fields.
     *
     * @return array<string, mixed>
     */
    public static function agentFilesystem(string $mount, bool $unresponsive = false, int $usedBytes = 32_212_254_720): array
    {
        if ($unresponsive) {
            return ['mount' => $mount, 'device' => 'nas:/backups', 'fstype' => 'nfs4', 'unresponsive' => true];
        }

        return [
            'mount' => $mount,
            'device' => $mount === '/' ? 'rootfs' : '/dev/sdb1',
            'fstype' => $mount === '/' ? 'rootfs' : 'xfs',
            'unresponsive' => false,
            'total_bytes' => 107_374_182_400,
            'used_bytes' => $usedBytes,
            'available_bytes' => 107_374_182_400 - $usedBytes,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $observed
     * @return array{id: string, type: string, status: string, message: ?string, observed: ?array<string, mixed>}
     */
    public static function agentCheckResult(string $checkUuid, HostCheckStatus $status, ?string $message = null, ?array $observed = null, string $type = 'mount_present'): array
    {
        return ['id' => $checkUuid, 'type' => $type, 'status' => $status->value, 'message' => $message, 'observed' => $observed];
    }

    /**
     * Runs ProcessAgentReport synchronously with the body as the report endpoint would hand it over: validated by
     * AgentReportRequest's rules (unknown keys dropped), received now unless `$receivedAt` says otherwise.
     *
     * @param  array<string, mixed>  $body
     */
    public static function dispatchReport(Server $server, array $body, ?CarbonImmutable $receivedAt = null): void
    {
        $validated = Validator::make($body, new AgentReportRequest()->rules())->validate();

        dispatch_sync(new ProcessAgentReport($server->id, $validated, ($receivedAt ?? now())->toIso8601String()));
    }
}
