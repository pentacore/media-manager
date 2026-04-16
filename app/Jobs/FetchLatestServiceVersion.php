<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServiceConnection;
use App\Services\GitHub\GitHubReleaseClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchLatestServiceVersion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public const array REPO_MAP = [
        'sonarr' => 'Sonarr/Sonarr',
        'radarr' => 'Radarr/Radarr',
        'seerr' => 'seerr-team/seerr',
        'emby' => 'MediaBrowser/Emby.Releases', // Emby is closed-source, but this is the canonical repo and latest release should be correct
    ];

    public function __construct(public ServiceConnection $serviceConnection) {}

    public function handle(GitHubReleaseClient $gitHubReleaseClient): void
    {
        $repo = self::REPO_MAP[$this->serviceConnection->type->value] ?? null;

        if ($repo === null) {
            return;
        }

        $latest = $gitHubReleaseClient->latestRelease($repo);

        if ($latest === null) {
            return;
        }

        $this->serviceConnection->forceFill(['latest_version' => $latest])->saveQuietly();
    }
}
