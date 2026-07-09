<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GitHub\GitHubReleaseClient;
use App\Support\AppVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Description('Check GitHub for the latest MediaManager release and cache it for the UI update hint.')]
#[Signature('app:check-version')]
class CheckAppVersion extends Command
{
    public function handle(GitHubReleaseClient $gitHubReleaseClient): int
    {
        $latest = $gitHubReleaseClient->latestRelease(AppVersion::REPO);

        if ($latest === null) {
            // Expected without a services.github.token (private repo) or
            // during GitHub hiccups; the client already logged the cause.
            $this->warn('Could not determine the latest release; keeping cached value.');

            return self::SUCCESS;
        }

        // TTL 2 days: a missed daily run keeps the hint alive, a dead
        // scheduler eventually clears it instead of pinning a stale version.
        Cache::put(AppVersion::CACHE_KEY, $latest, now()->addDays(2));

        $this->info(sprintf('Latest release: %s', $latest));

        return self::SUCCESS;
    }
}
