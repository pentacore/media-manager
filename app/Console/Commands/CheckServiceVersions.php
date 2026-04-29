<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchLatestServiceVersion;
use App\Models\ServiceConnection;
use Illuminate\Console\Command;
use Override;

class CheckServiceVersions extends Command
{
    #[Override]
    protected $signature = 'services:check-versions';

    #[Override]
    protected $description = 'Check upstream GitHub releases for each service type and store latest_version.';

    public function handle(): int
    {
        $connections = ServiceConnection::where('is_active', true)->get();
        $checked = 0;

        foreach ($connections as $connection) {
            if (! array_key_exists((string) $connection->type->value, FetchLatestServiceVersion::REPO_MAP)) {
                continue;
            }

            app()->call([new FetchLatestServiceVersion($connection), 'handle']);
            $checked++;
        }

        $this->info(sprintf('Updated latest_version for %d service(s).', $checked));

        return self::SUCCESS;
    }
}
