<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\Server;
use App\Models\ServerCheck;

/**
 * The body the report endpoint answers with: the platform's config version, and the full check config only when the agent's copy is out of date.
 */
final readonly class AgentConfigResponse
{
    /**
     * `config` is null when the agent already runs the current version; otherwise it lists every enabled check (possibly none).
     * `params` is an object so an empty set encodes as `{}`, never `[]`.
     *
     * @return array{config_version: int, config: array{checks: list<array{id: string, type: string, params: object}>}|null, report_interval_seconds: int, server_time: string}
     */
    public function for(Server $server, int $agentConfigVersion): array
    {
        return [
            'config_version' => $server->config_version,
            'config' => $server->config_version === $agentConfigVersion ? null : ['checks' => $this->checks($server)],
            'report_interval_seconds' => $server->report_interval_seconds,
            'server_time' => now()->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * @return list<array{id: string, type: string, params: object}>
     */
    private function checks(Server $server): array
    {
        return $server->checks()
            ->where('is_enabled', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (ServerCheck $serverCheck): array => [
                'id' => $serverCheck->uuid,
                'type' => $serverCheck->type->value,
                'params' => (object) $serverCheck->params,
            ])
            ->values()
            ->all();
    }
}
