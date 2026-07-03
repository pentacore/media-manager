<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Recompute the SABnzbd queued + still-in-history counts and broadcast them to the sidebar.')]
#[Signature('sabnzbd:refresh-download-counts')]
class RefreshSabnzbdDownloadCounts extends Command
{
    public function handle(SabnzbdDownloadCounter $sabnzbdDownloadCounter): void
    {
        $counts = $sabnzbdDownloadCounter->recompute();

        $this->info(sprintf('SAB downloads — queued: %d · completed: %d', $counts['queued'], $counts['completed']));
    }
}
