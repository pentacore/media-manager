<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Server;

/**
 * Request fixtures shared by the agent API feature tests.
 */
final class AgentReportFixtures
{
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
}
