<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\Server;
use Illuminate\Support\Str;

/**
 * Generates and hashes the two credentials of the agent protocol: the single-use enrollment token and the long-lived agent secret.
 */
final readonly class AgentTokens
{
    public function newEnrollmentToken(): string
    {
        return Str::random(32);
    }

    public function newAgentSecret(): string
    {
        return Str::random(48);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * The bearer credential the agent stores: the public server uuid and the secret, joined by a dot.
     */
    public function agentToken(Server $server, string $secret): string
    {
        return sprintf('%s.%s', $server->uuid, $secret);
    }
}
