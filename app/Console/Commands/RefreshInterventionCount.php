<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Library\InterventionCounter;
use Illuminate\Console\Command;
use Override;

class RefreshInterventionCount extends Command
{
    #[Override]
    protected $signature = 'library:refresh-intervention-count';

    #[Override]
    protected $description = 'Recompute the count of *arr download-queue items needing manual intervention and broadcast it.';

    public function handle(InterventionCounter $interventionCounter): void
    {
        $count = $interventionCounter->recompute();

        $this->info(sprintf('Manual-intervention queue items: %d', $count));
    }
}
