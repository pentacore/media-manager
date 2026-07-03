<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchLatestServiceVersion;
use App\Models\ServiceConnection;
use App\Support\ServiceCheckBatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Check upstream GitHub releases for each service type and store latest_version.')]
#[Signature('services:check-versions')]
class CheckServiceVersions extends Command
{
    public function handle(): int
    {
        $connections = ServiceConnection::where('is_active', true)
            ->get()
            ->filter(static fn (ServiceConnection $serviceConnection): bool => array_key_exists(
                (string) $serviceConnection->type->value,
                FetchLatestServiceVersion::REPO_MAP,
            ));

        if ($connections->isEmpty()) {
            $this->info('No active service connections with a known upstream repo.');

            return self::SUCCESS;
        }

        $batch = ServiceCheckBatch::dispatchVersions($connections);

        $this->info(sprintf('Dispatched batch %s for %d service(s).', $batch->id, $connections->count()));

        return self::SUCCESS;
    }
}
