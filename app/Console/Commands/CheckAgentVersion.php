<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GitHub\GitHubReleaseClient;
use App\Services\HostMonitoring\AgentRelease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Description('Check GitHub for the latest Lookout agent release and cache it for the outdated-agent hint.')]
#[Signature('servers:check-agent-version')]
class CheckAgentVersion extends Command
{
    public function handle(GitHubReleaseClient $gitHubReleaseClient): int
    {
        $latest = $gitHubReleaseClient->latestRelease(AgentRelease::repo());

        if ($latest === null) {
            // A repo without releases answers 404 until the first one ships;
            // keep whatever is cached and stay quiet about it.
            Log::debug('CheckAgentVersion: no Lookout release found; keeping cached value.', [
                'repo' => AgentRelease::repo(),
            ]);

            return self::SUCCESS;
        }

        // TTL 2 days: a missed daily run keeps the hint alive, a dead
        // scheduler eventually clears it instead of pinning a stale version.
        Cache::put(AgentRelease::CACHE_KEY, $latest, now()->addDays(2));

        $this->info(sprintf('Latest Lookout release: %s', $latest));

        return self::SUCCESS;
    }
}
