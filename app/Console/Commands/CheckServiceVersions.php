<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceConnection;
use App\Services\GitHub\GitHubReleaseClient;
use Illuminate\Console\Command;

class CheckServiceVersions extends Command
{
    #[\Override]
    protected $signature = 'services:check-versions';

    #[\Override]
    protected $description = 'Check upstream GitHub releases for each service type and store latest_version.';

    private const array REPO_MAP = [
        'sonarr' => 'Sonarr/Sonarr',
        'radarr' => 'Radarr/Radarr',
        // TODO: verify Seerr repo location (Jellyseerr + Overseerr merger)
        'seerr' => 'Fallenbagel/jellyseerr',
    ];

    public function handle(GitHubReleaseClient $gitHubReleaseClient): int
    {
        $connections = ServiceConnection::where('is_active', true)->get();
        $checked = 0;

        foreach ($connections as $connection) {
            $repo = self::REPO_MAP[$connection->type->value] ?? null;

            if ($repo === null) {
                // Emby is closed-source — skip silently
                continue;
            }

            $latest = $gitHubReleaseClient->latestRelease($repo);

            if ($latest === null) {
                $this->warn(sprintf('Could not fetch latest release for %s (%s).', $connection->name, $repo));

                continue;
            }

            $connection->forceFill(['latest_version' => $latest])->save();
            $checked++;
        }

        $this->info(sprintf('Updated latest_version for %d service(s).', $checked));

        return self::SUCCESS;
    }
}
