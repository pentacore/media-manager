<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Library\WantedCounter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Recompute the monitored missing episodes + movies count behind the Wanted sidebar badge.')]
#[Signature('library:refresh-wanted-count')]
class RefreshWantedCount extends Command
{
    public function handle(WantedCounter $wantedCounter): int
    {
        $count = $wantedCounter->recompute();

        $this->info(sprintf('Wanted missing items: %d', $count));

        return self::SUCCESS;
    }
}
