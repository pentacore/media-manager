<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Issues the single-use enrollment token an admin pastes into the agent installer and exchanges it for the agent token.
 */
final readonly class ServerEnrollment
{
    public function __construct(private AgentTokens $agentTokens) {}

    /**
     * Stores a fresh token hash with its expiry and returns the plain token. Only the hash is kept, so the plain token is shown once.
     */
    public function issueToken(Server $server): string
    {
        $token = $this->agentTokens->newEnrollmentToken();

        $server->update([
            'enrollment_token_hash' => AgentTokens::hash($token),
            'enrollment_expires_at' => now()->addHours((int) config('mediamanager.monitoring.enrollment_ttl_hours', 24)),
        ]);

        return $token;
    }

    /**
     * Exchanges a usable enrollment token for the agent token. The row stays locked so two racing agents cannot both win; the token hash is kept so a replay reads as spent.
     *
     * @param  array{hostname: string, os: ?string, kernel: ?string, arch: ?string, agent_version: string}  $host
     * @return array{server: Server, agent_token: string}
     *
     * @throws EnrollmentTokenUnknown
     * @throws EnrollmentTokenSpent
     */
    public function enroll(string $enrollmentToken, array $host): array
    {
        return DB::transaction(function () use ($enrollmentToken, $host): array {
            $server = Server::query()
                ->where('enrollment_token_hash', AgentTokens::hash($enrollmentToken))
                ->lockForUpdate()
                ->first();

            throw_if($server === null, EnrollmentTokenUnknown::class, 'No server holds this enrollment token.');

            throw_unless($server->enrollmentIsUsable(), EnrollmentTokenSpent::class, 'This enrollment token was already used or has expired.');

            $secret = $this->agentTokens->newAgentSecret();

            $server->update([
                'token_hash' => AgentTokens::hash($secret),
                'enrolled_at' => now(),
                'enrollment_expires_at' => null,
                'hostname' => $host['hostname'],
                'os' => $host['os'] ?? null,
                'kernel' => $host['kernel'] ?? null,
                'arch' => $host['arch'] ?? null,
                'agent_version' => $host['agent_version'],
            ]);

            return ['server' => $server, 'agent_token' => $this->agentTokens->agentToken($server, $secret)];
        });
    }
}
