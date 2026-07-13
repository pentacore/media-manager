<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncAnimeMappingJob;
use App\Models\AnimeIdMap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('anime:sync-mappings {--if-empty : Only run when the mapping table is empty}')]
#[Description('Reload the anime id mapping table from the Fribb anime-lists dataset')]
class SyncAnimeMappings extends Command
{
    public function handle(): int
    {
        if ($this->option('if-empty') && AnimeIdMap::query()->exists()) {
            $this->info('Anime id map already populated; skipping.');

            return self::SUCCESS;
        }

        $this->info('Syncing anime id mappings...');
        new SyncAnimeMappingJob()->handle();

        $this->info('Done. Rows: '.AnimeIdMap::query()->count());

        return self::SUCCESS;
    }
}
