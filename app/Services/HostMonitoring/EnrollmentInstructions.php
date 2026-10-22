<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\Server;

/**
 * Builds what the admin needs to install the agent: the one-time token and the install steps that carry it.
 * The result is flashed once and never stored, so the plain token is shown exactly once.
 */
final readonly class EnrollmentInstructions
{
    /**
     * @return array{server_id: int, token: string, expires_at: string, platform_url: string, debian_command: string, unraid_plugin_url: string}
     */
    public function for(Server $server, string $token): array
    {
        $platformUrl = (string) config('app.url');

        return [
            'server_id' => $server->id,
            'token' => $token,
            'expires_at' => (string) $server->enrollment_expires_at?->toIso8601String(),
            'platform_url' => $platformUrl,
            'debian_command' => sprintf(
                'curl -fsSL %s | sudo sh -s -- --url %s --token %s',
                config('mediamanager.monitoring.install_script_url'),
                $platformUrl,
                $token,
            ),
            'unraid_plugin_url' => (string) config('mediamanager.monitoring.unraid_plugin_url'),
        ];
    }
}
