<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncAnimeMappingJob;
use App\Models\AnimeIdMap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('anime:sync-mappings {--if-empty : Only run when the mapping table is empty}')]
#[Description('Reload the anime id mapping table from the Fribb anime-lists dataset')]
class SyncAnimeMappings extends Command
{
    public function handle(): int
    {
        // Only dataset-sourced rows count as "populated" — a lone user-confirmed
        // match must not suppress the initial bootstrap.
        if ($this->option('if-empty') && AnimeIdMap::query()->where('user_confirmed', false)->exists()) {
            $this->info('Anime id map already populated; skipping.');

            return self::SUCCESS;
        }

        $this->info('Syncing anime id mappings...');

        try {
            $rows = new SyncAnimeMappingJob()->handle();
        } catch (Throwable $throwable) {
            // Rejected/undersized/malformed datasets throw and leave the table
            // untouched — report that instead of a false "Done".
            $this->error('Anime mapping sync failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Done. %d mappings loaded.', $rows));

        return self::SUCCESS;
    }
}
