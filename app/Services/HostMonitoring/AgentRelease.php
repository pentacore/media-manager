<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use Illuminate\Support\Facades\Cache;

final class AgentRelease
{
    public const string CACHE_KEY = 'lookout:latest_version';

    /**
     * The GitHub repo ("owner/name") that publishes the Lookout host agent.
     */
    public static function repo(): string
    {
        return (string) config('mediamanager.monitoring.agent_repo');
    }

    /**
     * Latest published Lookout release without its leading "v", cached by
     * servers:check-agent-version. Null until a release exists and was fetched.
     */
    public static function latest(): ?string
    {
        $latest = Cache::get(self::CACHE_KEY);

        return is_string($latest) && $latest !== '' ? $latest : null;
    }

    /**
     * Whether an agent runs a release older than the latest one. An agent that
     * has not reported a version, a "dev" build and anything that is not a
     * version number are never outdated: there is nothing to upgrade from.
     */
    public static function isOutdated(?string $agentVersion): bool
    {
        $latest = self::latest();

        if ($agentVersion === null || $latest === null) {
            return false;
        }

        $agentVersion = ltrim($agentVersion, 'vV');

        if (preg_match('/^\d+(\.\d+)*([-+.]?[0-9A-Za-z.]+)?$/', $agentVersion) !== 1) {
            return false;
        }

        return version_compare($latest, $agentVersion, '>');
    }
}
