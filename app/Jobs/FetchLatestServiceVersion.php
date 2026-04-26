<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UserRole;
use App\Events\ServiceLatestVersionFetched;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\ServiceUpdateAvailable;
use App\Services\GitHub\GitHubReleaseClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

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
        'prowlarr' => 'Prowlarr/Prowlarr',
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

        $previousLatest = $this->serviceConnection->latest_version;
        $current = $this->serviceConnection->version;

        $this->serviceConnection->forceFill(['latest_version' => $latest])->saveQuietly();

        if ($this->serviceConnection->wasChanged('latest_version')) {
            event(new ServiceLatestVersionFetched($this->serviceConnection->fresh()));
        }

        // Notify only when this is a *newly* discovered upgrade. We require the
        // installed version to be known (otherwise we can't tell it's an upgrade)
        // and the GitHub-reported tag to differ from both the previous tag (no
        // re-spam) and the installed version.
        if ($current === null || $latest === $current || $latest === $previousLatest) {
            return;
        }

        $admins = User::query()
            ->where('role', UserRole::Admin->value)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new ServiceUpdateAvailable(
            $this->serviceConnection,
            $latest,
            $current,
        ));
    }
}
