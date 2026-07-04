<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Library\InterventionCounter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Recompute the count of *arr download-queue items needing manual intervention and broadcast it.')]
#[Signature('library:refresh-intervention-count')]
class RefreshInterventionCount extends Command
{
    public function handle(InterventionCounter $interventionCounter): void
    {
        $count = $interventionCounter->recompute();

        $this->info(sprintf('Manual-intervention queue items: %d', $count));
    }
}
